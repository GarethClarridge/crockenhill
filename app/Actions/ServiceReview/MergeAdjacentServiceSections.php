<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\Media\Video\VideoStorageService;

class MergeAdjacentServiceSections
{
    public function __construct(
        private readonly ServiceSectionSyncService $syncService,
        private readonly VideoStorageService $videoStorageService,
        private readonly ExtractedSectionMediaChecker $mediaChecker,
    ) {}

    /**
     * Merge two adjacent same-type service sections into one.
     *
     * Returns null on success, or a human-readable error string on failure.
     */
    public function execute(ServiceSection $primary, ServiceSection $secondary, int $userId): ?string
    {
        $error = $this->validate($primary, $secondary);
        if ($error !== null) {
            return $error;
        }

        // The longer section is the primary
        if ((float) $secondary->duration > (float) $primary->duration) {
            [$primary, $secondary] = [$secondary, $primary];
        }

        $startTime = min((float) $primary->start_time, (float) $secondary->start_time);
        $endTime = max((float) $primary->end_time, (float) $secondary->end_time);

        $mergedSourceIds = array_values(array_unique(array_merge($primary->source_segment_ids, $secondary->source_segment_ids)));

        $metadata = $primary->metadata?->toArray() ?? [];
        $secondaryMetadata = $secondary->metadata?->toArray() ?? [];

        if (isset($secondaryMetadata['review_reason'])) {
            $metadata['merged_review_reason'] = $secondaryMetadata['review_reason'];
        }

        $metadata['manually_merged'] = true;
        $metadata['manually_merged_at'] = now()->toIso8601String();
        $metadata['manually_merged_by_user_id'] = $userId;

        $primary->start_time = $startTime;
        $primary->end_time = $endTime;
        $primary->duration = max(0.0, $endTime - $startTime);
        $primary->source_segment_ids = $mergedSourceIds;
        $primary->needs_manual_review = $primary->needs_manual_review || $secondary->needs_manual_review;

        if ($this->mediaChecker->hasExtractedMedia($primary)) {
            $primary->extracted_video_path = null;
            $primary->extracted_audio_path = null;
            $primary->extracted_at = null;
            $primary->publication_status = ServiceSectionPublicationStatus::NotApplicable;
        }

        $primary->metadata = ServiceSectionMetadata::fromArray($metadata);
        $primary->save();

        $this->syncService->removeSection($secondary);

        $processingLog = $primary->processingLog;

        if (is_string($processingLog->source_file_path)
            && $processingLog->source_file_path !== ''
            && $this->videoStorageService->sourceVideoExistsForPath($processingLog->source_file_path)
        ) {
            PrepareSectionPublicationCandidates::dispatchStandalone($processingLog);
        }

        return null;
    }

    private function validate(ServiceSection $primary, ServiceSection $secondary): ?string
    {
        if ($primary->media_processing_log_id !== $secondary->media_processing_log_id) {
            return 'Both sections must belong to the same processing run.';
        }

        if ($primary->section_type !== $secondary->section_type) {
            return 'Both sections must have the same section type.';
        }

        $publishedStatuses = [ServiceSectionPublicationStatus::Published];
        if (in_array($primary->publication_status, $publishedStatuses, true)
            || in_array($secondary->publication_status, $publishedStatuses, true)) {
            return 'Published sections cannot be merged.';
        }

        $maxGap = (float) config(
            'media-processing.section_classification.adjacent_merge_max_gap_seconds',
            2
        );

        $gap = abs((float) $secondary->start_time - (float) $primary->end_time);
        $reverseGap = abs((float) $primary->start_time - (float) $secondary->end_time);
        $minGap = min($gap, $reverseGap);

        if ($minGap > $maxGap) {
            return 'The sections are not close enough to merge (gap exceeds the configured threshold).';
        }

        $minOrder = min($primary->section_order, $secondary->section_order);
        $maxOrder = max($primary->section_order, $secondary->section_order);

        $hasSectionsBetween = ServiceSection::query()
            ->where('media_processing_log_id', $primary->media_processing_log_id)
            ->whereBetween('section_order', [$minOrder + 1, $maxOrder - 1])
            ->exists();

        if ($hasSectionsBetween) {
            return 'There are other sections between these two — they cannot be merged.';
        }

        return null;
    }
}
