<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Contracts\SectionPublicationHandler;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\Media\Audio\AudioEnhancementService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Song\SongVideoService;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Handler for enhancing and publishing song segments extracted from livestreams.
 *
 * This handler manages the "livestream-to-song" pipeline, which includes downloading
 * extracted video clips, applying audio enhancement (normalization) if possible,
 * and promoting the final video asset to the public sermon disk where it is
 * linked to a canonical Song model.
 */
class SongPublicationHandler implements SectionPublicationHandler
{
    use SanitizesLogData;

    /**
     * @param  SongVideoService  $songVideoService  Service for managing song video records
     * @param  ServiceSectionPublicationTransitionService  $publicationTransitions  Service for managing section state transitions
     * @param  AudioEnhancementService  $audioEnhancement  Service for normalizing audio in video files
     * @param  StorageAdapterHelper  $storageHelper  Service for cross-disk storage operations
     * @param  SongPublicationReviewPolicy  $reviewPolicy  Names the doubts that stop a clip publishing itself
     */
    public function __construct(
        private readonly SongVideoService $songVideoService,
        private readonly ServiceSectionPublicationTransitionService $publicationTransitions,
        private readonly AudioEnhancementService $audioEnhancement,
        private readonly StorageAdapterHelper $storageHelper,
        private readonly SongPublicationReviewPolicy $reviewPolicy,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function requiresAudioExtraction(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool
    {
        $videoPath = $section->extracted_video_path;

        if (! is_string($videoPath) || $videoPath === '') {
            return false;
        }

        return Storage::disk($section->extractedAssetDisk())->exists($videoPath);
    }

    /**
     * Determine if a song section can enter publication preparation.
     *
     * Confirmed and inferred matches with a linked canonical Song record can be
     * prepared. The review policy keeps inferred matches from being published
     * automatically.
     *
     * @param  ServiceSection  $section  The section to evaluate
     * @return bool True if the section is linked to a valid song
     */
    public function isEligible(ServiceSection $section): bool
    {
        if (! $section->hasConfirmedSongMatch() && ! $section->hasInferredSongMatch()) {
            return false;
        }

        $item = $section->churchServiceItem;

        return $item !== null && $item->song_id !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function afterExtraction(ServiceSection $section): void
    {
        // No post-extraction enrichment needed for songs.
    }

    /**
     * A whole, singular, corroborated song clip publishes itself; anything the
     * review policy can name a doubt about reaches a person first, with the
     * doubt recorded on the section so they can see what it was.
     */
    public function requiresApproval(ServiceSection $section): bool
    {
        $assessment = $this->reviewPolicy->assess($section);
        $reasons = $assessment['reasons'];
        $existingMetadata = $section->metadata?->toArray() ?? [];
        $metadata = $existingMetadata;
        $metadata[SongPublicationBoundaryEvidenceService::METADATA_KEY] = $assessment['boundary_evidence'];

        if ($reasons !== []) {
            $existingReview = $metadata['song_publication_review'] ?? null;
            $decidedAt = is_array($existingReview)
                && ($existingReview['reasons'] ?? null) === $reasons
                && is_string($existingReview['decided_at'] ?? null)
                ? $existingReview['decided_at']
                : now()->toISOString();

            $metadata['song_publication_review'] = [
                'reasons' => $reasons,
                'decided_at' => $decidedAt,
            ];
        } else {
            unset($metadata['song_publication_review']);
        }

        $boundaryChanged = ($existingMetadata[SongPublicationBoundaryEvidenceService::METADATA_KEY] ?? null)
            != $metadata[SongPublicationBoundaryEvidenceService::METADATA_KEY];
        $reviewChanged = ($existingMetadata['song_publication_review'] ?? null)
            != ($metadata['song_publication_review'] ?? null);

        if ($boundaryChanged || $reviewChanged) {
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        }

        if ($reasons === []) {
            return false;
        }

        Log::info('Holding a song clip for review before publication', $this->sanitizeArrayForLog([
            'service_section_id' => $section->id,
            'reasons' => array_column($reasons, 'kind'),
        ]));

        return true;
    }

    /**
     * Publish a song section by enhancing its audio and promoting the asset.
     *
     * Downloads the extracted video to local temp storage, applies normalization
     * via the AudioEnhancementService, and promotes the final MP4 to the public
     * sermon disk before creating the SongVideo record.
     *
     * @param  ServiceSection  $section  The section to publish
     *
     * @throws \RuntimeException If assets are missing, song links are broken,
     *                           or storage promotion fails.
     */
    public function publish(ServiceSection $section): void
    {
        // Idempotent: skip if a SongVideo already exists for this section.
        if (SongVideo::query()->where('service_section_id', $section->id)->exists()) {
            Log::info('SongPublicationHandler: SongVideo already exists for section, skipping', $this->sanitizeArrayForLog([
                'service_section_id' => $section->id,
            ]));

            return;
        }

        $videoPath = $section->extracted_video_path;

        if (! is_string($videoPath) || $videoPath === '') {
            throw new \RuntimeException('Section video path missing for song publication');
        }

        if (! Storage::disk($section->extractedAssetDisk())->exists($videoPath)) {
            throw new \RuntimeException('Section video file is missing for song publication');
        }

        $item = $section->churchServiceItem;
        if ($item === null || $item->song_id === null) {
            throw new \RuntimeException('Section has no linked song for publication');
        }

        $localTempDownload = null;
        $enhancedTempPath = null;

        try {
            $sourceDiskName = $section->extractedAssetDisk();
            $localInputPath = $this->storageHelper->downloadToTemp(
                $videoPath,
                $sourceDiskName,
                'local',
                'temp/song-enhancement'
            );

            // Only track the download temp file if the disk is remote (downloadToTemp created it).
            if ($this->storageHelper->isS3CompatibleDisk(Storage::disk($sourceDiskName))) {
                $localTempDownload = $localInputPath;
            }

            $enhancedTempPath = $this->audioEnhancement->enhanceVideo($localInputPath, 'song-'.$section->id);

            $promotedPath = $enhancedTempPath !== null
                ? $this->promoteLocalFileAsVideo($section, $enhancedTempPath)
                : $this->promoteExtractedVideo($section, $videoPath);
        } finally {
            if ($localTempDownload !== null && file_exists($localTempDownload)) {
                @unlink($localTempDownload);
            }

            if ($enhancedTempPath !== null && file_exists($enhancedTempPath)) {
                @unlink($enhancedTempPath);
            }
        }

        $section->extracted_video_path = $promotedPath;

        $this->songVideoService->createFromExtraction($section, $promotedPath);

        if (! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::Published)) {
            throw new \RuntimeException('Invalid state transition when publishing song section');
        }

        $section->published_at = now();
        $section->unpublished_expires_at = null;
        $section->save();
    }

    /**
     * Handle removal of a section by deleting its associated SongVideo and file.
     *
     * @param  ServiceSection  $section  The removed section
     */
    public function onSectionRemoved(ServiceSection $section): void
    {
        $songVideo = SongVideo::query()
            ->where('service_section_id', $section->id)
            ->first();

        if (! $songVideo instanceof SongVideo) {
            return;
        }

        $disk = filled($songVideo->asset_disk)
            ? (string) $songVideo->asset_disk
            : $this->sermonDisk();

        Storage::disk($disk)->delete($songVideo->video_file_path);
        $songVideo->delete();

        Log::info('SongPublicationHandler: cleaned up SongVideo for removed section', $this->sanitizeArrayForLog([
            'service_section_id' => $section->id,
            'song_video_id' => $songVideo->id,
        ]));
    }

    private function promoteExtractedVideo(ServiceSection $section, string $sourcePath): string
    {
        /** @var ChurchServiceItem $item validated in publish() */
        $item = $section->churchServiceItem;
        $targetPath = 'sermons/songs/'.$item->song_id.'/'.$section->id.'.mp4';

        $sourceDisk = $section->extractedAssetDisk();
        $targetDisk = $this->sermonDisk();

        if ($sourceDisk === $targetDisk && $sourcePath === $targetPath) {
            return $sourcePath;
        }

        $sourceStream = Storage::disk($sourceDisk)->readStream($sourcePath);
        if (! is_resource($sourceStream)) {
            throw new \RuntimeException('Unable to read extracted song video for publication');
        }

        try {
            $written = Storage::disk($targetDisk)->put($targetPath, $sourceStream);
        } finally {
            fclose($sourceStream);
        }

        if ($written !== true || ! Storage::disk($targetDisk)->exists($targetPath)) {
            throw new \RuntimeException('Unable to publish song video to the sermon disk');
        }

        if ($sourceDisk !== $targetDisk || $sourcePath !== $targetPath) {
            Storage::disk($sourceDisk)->delete($sourcePath);
        }

        return $targetPath;
    }

    /**
     * Promote a locally-enhanced video file (absolute path) to the sermon disk.
     *
     * Used when enhancement produces a temp file that must be streamed to storage
     * rather than copied between storage disks.
     */
    private function promoteLocalFileAsVideo(ServiceSection $section, string $localFilePath): string
    {
        /** @var ChurchServiceItem $item validated in publish() */
        $item = $section->churchServiceItem;
        $targetPath = 'sermons/songs/'.$item->song_id.'/'.$section->id.'.mp4';
        $targetDisk = $this->sermonDisk();

        $fileStream = fopen($localFilePath, 'r');
        if (! is_resource($fileStream)) {
            throw new \RuntimeException('Unable to read enhanced song video for publication');
        }

        try {
            $written = Storage::disk($targetDisk)->put($targetPath, $fileStream);
        } finally {
            fclose($fileStream);
        }

        if ($written !== true || ! Storage::disk($targetDisk)->exists($targetPath)) {
            throw new \RuntimeException('Unable to publish enhanced song video to the sermon disk');
        }

        // Remove the original extracted clip from the source disk now that the enhanced version is promoted.
        $sourcePath = $section->extracted_video_path;
        if (is_string($sourcePath) && $sourcePath !== '') {
            Storage::disk($section->extractedAssetDisk())->delete($sourcePath);
        }

        return $targetPath;
    }

    private function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local'));
    }
}
