<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Contracts\SectionPublicationHandler;
use App\Data\ServiceSectionMetadata;
use App\Enums\AudioProfile;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Song\SongVideoService;
use App\Support\MediaProcessingVersion;
use App\Support\PublicationCandidate;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Handler for publishing song segments extracted from livestreams.
 *
 * The candidate clip is cut with the music profile's sound (§6.3), so publishing
 * promotes it unchanged to the public sermon disk, where it is linked to a
 * canonical Song model.
 */
class SongPublicationHandler implements SectionPublicationHandler
{
    use SanitizesLogData;

    /**
     * @param  SongVideoService  $songVideoService  Service for managing song video records
     * @param  ServiceSectionPublicationTransitionService  $publicationTransitions  Service for managing section state transitions
     * @param  StorageAdapterHelper  $storageHelper  Service for cross-disk storage operations
     * @param  SongPublicationReviewPolicy  $reviewPolicy  Names the doubts that stop a clip publishing itself
     */
    public function __construct(
        private readonly SongVideoService $songVideoService,
        private readonly ServiceSectionPublicationTransitionService $publicationTransitions,
        private readonly StorageAdapterHelper $storageHelper,
        private readonly SongPublicationReviewPolicy $reviewPolicy,
        private readonly ExtractedMediaDurationProbe $durationProbe,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function requiresAudioExtraction(): bool
    {
        return false;
    }

    public function audioProfile(): AudioProfile
    {
        return AudioProfile::Music;
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
     * Publish a song section by promoting its candidate clip.
     *
     * Measures the clip's length, promotes the MP4 to the public sermon disk
     * and creates the SongVideo record.
     *
     * @param  ServiceSection  $section  The section to publish
     *
     * @throws \RuntimeException If assets are missing, song links are broken,
     *                           or storage promotion fails.
     */
    public function publish(ServiceSection $section): void
    {
        // Idempotent: skip when this section's song video was published from this very candidate.
        if (SongVideo::query()->where('service_section_id', $section->id)->exists() && $this->isPublishedFromCurrentCandidate($section)) {
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

        // The candidate already carries the published sound (§6.3): treating it again here
        // undid song fades and was what listening never heard. One cut under other processing,
        // or before the recording's own audio settings changed, does not.
        $staleReason = PublicationCandidate::staleReason($section, $this->audioProfile());
        if ($staleReason !== null) {
            throw new \RuntimeException('Song candidate '.$staleReason.'; prepare the candidate again before publishing');
        }

        $localTempDownload = null;

        try {
            $sourceDiskName = $section->extractedAssetDisk();
            $localInputPath = $this->storageHelper->downloadToTemp(
                $videoPath,
                $sourceDiskName,
                'local',
                'temp/song-publication'
            );

            // Only track the download temp file if the disk is remote (downloadToTemp created it).
            if ($this->storageHelper->isS3CompatibleDisk(Storage::disk($sourceDiskName))) {
                $localTempDownload = $localInputPath;
            }

            $clipSeconds = $this->measuredClipSeconds($localInputPath, $section);
            $promotedPath = $this->promoteExtractedVideo($section, $videoPath);
        } finally {
            if ($localTempDownload !== null && file_exists($localTempDownload)) {
                @unlink($localTempDownload);
            }
        }

        $section->extracted_video_path = $promotedPath;
        $metadata = $section->metadata?->toArray() ?? [];
        $metadata['song_video_extraction'] = [
            'candidate_id' => PublicationCandidate::id($section),
            'media_signature' => $section->mediaSignature(),
            'media_processing' => MediaProcessingVersion::signature(),
            'generated_at' => now()->toIso8601String(),
        ];
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);

        $this->songVideoService->createFromExtraction($section, $promotedPath, $clipSeconds);

        if ($section->publication_status !== ServiceSectionPublicationStatus::Published
            && ! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::Published)) {
            throw new \RuntimeException('Invalid state transition when publishing song section');
        }

        $section->published_at = now();
        $section->unpublished_expires_at = null;
        $section->save();
    }

    /**
     * Whether the published song video was promoted from the candidate the section
     * holds now. A re-cut — new bounds, edges, processing or the recording's own
     * audio settings — gives the candidate a new identity, so the published clip
     * is stale until it is promoted.
     */
    public function isPublishedFromCurrentCandidate(ServiceSection $section): bool
    {
        $published = $section->metadata?->raw['song_video_extraction'] ?? null;
        $candidateId = PublicationCandidate::id($section);

        return is_array($published)
            && ($published['media_signature'] ?? null) === $section->mediaSignature()
            && $candidateId !== null
            && ($published['candidate_id'] ?? null) === $candidateId;
    }

    /**
     * Bring a published song up to date with a re-cut candidate.
     *
     * Its sound replaces what is public only when nothing about it needs hearing
     * first. A re-cut that is untreated, off target, too short to be a whole song,
     * or in any other way doubtful keeps the published clip, and the doubt is
     * recorded on the section for review.
     */
    public function refreshPublished(ServiceSection $section): void
    {
        if ($this->isPublishedFromCurrentCandidate($section)) {
            return;
        }

        if ($this->requiresApproval($section)) {
            Log::warning('Keeping a published song clip: its re-cut needs review first', $this->sanitizeArrayForLog([
                'service_section_id' => $section->id,
                'reasons' => array_column($section->metadata?->raw['song_publication_review']['reasons'] ?? [], 'kind'),
            ]));

            return;
        }

        $this->publish($section);
    }

    /**
     * The length of the clip about to be published, or null when it cannot be
     * measured, in which case the song video falls back to the section span.
     */
    private function measuredClipSeconds(string $localClipPath, ServiceSection $section): ?float
    {
        try {
            return $this->durationProbe->durationOf($localClipPath);
        } catch (\RuntimeException $exception) {
            Log::warning('Song clip length could not be measured; recording the section span instead', [
                'service_section_id' => $section->id,
                'clip_path' => $localClipPath,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
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

    private function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local'));
    }
}
