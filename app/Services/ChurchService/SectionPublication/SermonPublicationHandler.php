<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Contracts\SectionPublicationHandler;
use App\Data\SermonCreationOptions;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\Preacher\ChildrensTalkSpeakerService;
use App\Services\Processing\MediaProcessingIdentityResolver;
use App\Services\Sermon\SermonCreationService;
use App\Support\ServiceSectionConfidence;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Handler for promoting and publishing sermon segments extracted from livestreams.
 *
 * This handler manages the "livestream-to-sermon" pipeline, ensuring that extracted
 * audio and video clips are correctly promoted to public storage, sermon metadata
 * is resolved from the processing identity, and a canonical Sermon record is
 * created or enriched via the SermonCreationService.
 */
class SermonPublicationHandler implements SectionPublicationHandler
{
    use SanitizesLogData;

    /**
     * @param  ChildrensTalkSpeakerService  $childrensTalkSpeakerService  Service for detecting speakers in children's talks
     * @param  SermonCreationService  $sermonCreationService  Service for richness-aware sermon upserts
     * @param  MediaProcessingIdentityResolver  $identityResolver  Service for resolving date/service from processing logs
     * @param  ServiceSectionPublicationTransitionService  $publicationTransitions  Service for managing section state transitions
     * @param  ExtractedSectionMediaChecker  $mediaChecker  Service for verifying existence of extracted assets
     */
    public function __construct(
        private readonly ChildrensTalkSpeakerService $childrensTalkSpeakerService,
        private readonly SermonCreationService $sermonCreationService,
        private readonly MediaProcessingIdentityResolver $identityResolver,
        private readonly ServiceSectionPublicationTransitionService $publicationTransitions,
        private readonly ExtractedSectionMediaChecker $mediaChecker,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function requiresAudioExtraction(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function hasReusableExtractedMedia(ServiceSection $section): bool
    {
        return $this->mediaChecker->hasExtractedMedia($section);
    }

    /**
     * Determine if a sermon section is eligible for automated publication.
     *
     * Enforces the high-confidence requirement for automated sermon publication
     * to prevent low-confidence segments from being published without administrative
     * review.
     *
     * @param  ServiceSection  $section  The section to evaluate
     * @return bool True if the section meets confidence thresholds or if requirements are disabled
     */
    public function isEligible(ServiceSection $section): bool
    {
        $requireHighConfidence = (bool) config('media-processing.section_publishing.require_high_confidence', true);

        return ! $requireHighConfidence || ($section->confidence ?? 0.0) >= ServiceSectionConfidence::HIGH_THRESHOLD;
    }

    /**
     * {@inheritDoc}
     */
    public function afterExtraction(ServiceSection $section): void
    {
        if ($section->section_type === ServiceSectionType::ChildrensTalk) {
            $this->childrensTalkSpeakerService->detectAndStore($section);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function requiresApproval(ServiceSection $section): bool
    {
        return true;
    }

    /**
     * Publish an approved sermon section to the public sermon repository.
     *
     * Promotes extracted audio and video assets to permanent storage, resolves the
     * sermon's identity (date/service) from the parent processing log, and
     * initiates a richness-aware upsert of the Sermon record.
     *
     * @param  ServiceSection  $section  The approved section to publish
     *
     * @throws \RuntimeException If assets are missing, identity cannot be resolved,
     *                           or state transitions fail.
     */
    public function publish(ServiceSection $section): void
    {
        $processingLog = $section->processingLog;

        $videoPath = $section->extracted_video_path;
        $audioPath = $section->extracted_audio_path;

        if (! is_string($videoPath) || $videoPath === '') {
            throw new \RuntimeException('Section video path missing for approved publication');
        }
        if (! is_string($audioPath) || $audioPath === '') {
            throw new \RuntimeException('Section audio path missing for approved publication');
        }

        if (! Storage::disk($section->extractedAssetDisk())->exists($videoPath)) {
            throw new \RuntimeException('Section video file is missing for approved publication');
        }
        if (! Storage::disk($section->extractedAssetDisk())->exists($audioPath)) {
            throw new \RuntimeException('Section audio file is missing for approved publication');
        }

        $identity = $this->identityResolver->resolve($processingLog);
        if ($identity === null) {
            throw new \RuntimeException('Unable to resolve processing identity for section publication');
        }

        $sectionMetadata = $section->metadata?->toArray() ?? [];
        $publicationMetadata = is_array($sectionMetadata['publication'] ?? null)
            ? $sectionMetadata['publication']
            : [];
        $approvedSignature = $publicationMetadata['approved_signature'] ?? null;
        if (! is_string($approvedSignature) || $approvedSignature === '') {
            throw new \RuntimeException('Section approval signature is missing; re-approve before publishing');
        }

        $currentSignature = $section->classificationSignature();
        if (! hash_equals($approvedSignature, $currentSignature)) {
            throw new \RuntimeException('Section classification changed since approval; re-approve before publishing');
        }

        if (! $section->hasResolvedChildrensTalkSpeaker()) {
            throw new \RuntimeException("Children's talk speaker must be reviewed before publication");
        }

        $section->extracted_video_path = $this->promoteExtractedAsset(
            $section,
            $videoPath,
            'sermons/sections/'.$section->id.'/video.'.pathinfo($videoPath, PATHINFO_EXTENSION),
        );
        $section->extracted_audio_path = $this->promoteExtractedAsset(
            $section,
            $audioPath,
            'sermons/audio/'.basename($audioPath),
        );

        if (! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::Published)) {
            throw new \RuntimeException('Invalid state transition when publishing approved section');
        }

        $options = SermonCreationOptions::fromServiceSection(
            $section,
            $processingLog,
            $identity['date'],
            $identity['service']
        );

        $sermon = $this->sermonCreationService->createSermon($processingLog, $options);

        $section->published_sermon_id = $sermon->id;
        $section->published_at = now();
        $section->unpublished_expires_at = null;
        $section->save();
    }

    /**
     * Handle the removal of a section by logging a warning if it was already published.
     *
     * @param  ServiceSection  $section  The removed section
     */
    public function onSectionRemoved(ServiceSection $section): void
    {
        if ($section->published_sermon_id === null) {
            return;
        }

        Log::warning('Published service section removed during sync', $this->sanitizeArrayForLog([
            'service_section_id' => $section->id,
            'processing_log_id' => $section->media_processing_log_id,
            'published_sermon_id' => $section->published_sermon_id,
        ]));
    }

    private function promoteExtractedAsset(
        ServiceSection $section,
        string $sourcePath,
        string $targetPath,
    ): string {
        $sourceDisk = $section->extractedAssetDisk();
        $targetDisk = (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local'));

        if ($sourceDisk === $targetDisk && $sourcePath === $targetPath) {
            return $sourcePath;
        }

        $sourceStream = Storage::disk($sourceDisk)->readStream($sourcePath);
        if (! is_resource($sourceStream)) {
            throw new \RuntimeException('Unable to read approved section asset for publication');
        }

        try {
            $written = Storage::disk($targetDisk)->put($targetPath, $sourceStream);
        } finally {
            fclose($sourceStream);
        }

        if ($written !== true || ! Storage::disk($targetDisk)->exists($targetPath)) {
            throw new \RuntimeException('Unable to publish approved section asset to the public sermon disk');
        }

        if ($sourceDisk !== $targetDisk || $sourcePath !== $targetPath) {
            Storage::disk($sourceDisk)->delete($sourcePath);
        }

        return $targetPath;
    }
}
