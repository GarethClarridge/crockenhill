<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\LivestreamSegmentClassification;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingIdentityResolver;
use App\Services\Sermon\SermonCandidateConfidenceService;
use App\Support\ServiceSectionConfidence;
use Illuminate\Support\Collection;

/**
 * Orchestrates the initial identification and classification of service sections.
 *
 * This service transforms raw livestream segments into a structured sequence of
 * church service sections (Songs, Sermons, Readings, etc.). It currently implements
 * an "audio-only" baseline strategy that relies on acoustic segmentation and
 * preacher identification, which provides the foundation for later refinement
 * through OoS alignment and visual analysis.
 *
 * @phpstan-import-type ClassifiedSection from ServiceSectionSyncService
 */
class ServiceSectionClassifier
{
    private MediaProcessingIdentityResolver $identityResolver;

    private SermonCandidateConfidenceService $sermonConfidenceService;

    public function __construct(
        ?MediaProcessingIdentityResolver $identityResolver = null,
        ?SermonCandidateConfidenceService $sermonConfidenceService = null
    ) {
        $this->identityResolver = $identityResolver ?? new MediaProcessingIdentityResolver;
        $this->sermonConfidenceService = $sermonConfidenceService ?? new SermonCandidateConfidenceService;
    }

    /**
     * Identifies and types all audible sections within a processing log.
     *
     * @param  MediaProcessingLog  $processingLog  The log to analyze
     * @return array{
     *     skipped: bool,
     *     skip_reason: ?string,
     *     sections: array<int, ClassifiedSection>
     * }
     */
    public function classify(MediaProcessingLog $processingLog): array
    {
        $this->resolveChurchService($processingLog);

        /** @var Collection<int, LivestreamSegment> $segments */
        $segments = LivestreamSegment::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('segment_order')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        return [
            'skipped' => false,
            'skip_reason' => null,
            'sections' => $this->classifyFromAudioOnlySegments($segments),
        ];
    }

    private function resolveChurchService(MediaProcessingLog $processingLog): ?ChurchService
    {
        if ($processingLog->churchService instanceof ChurchService) {
            return $processingLog->churchService;
        }

        if ($processingLog->church_service_id !== null) {
            return $processingLog->churchService()->first();
        }

        $identity = $this->identityResolver->resolve($processingLog);

        if ($identity === null) {
            return null;
        }

        $churchService = ChurchService::query()
            ->where('date', $identity['date'])
            ->where('service', $identity['service']->value)
            ->first();

        if ($churchService instanceof ChurchService) {
            $processingLog->forceFill([
                'church_service_id' => $churchService->id,
            ])->saveQuietly();
        }

        return $churchService;
    }

    /**
     * Maps raw audible segments to their initial section classifications.
     *
     * @param  Collection<int, LivestreamSegment>  $segments
     * @return array<int, ClassifiedSection>
     */
    private function classifyFromAudioOnlySegments(Collection $segments): array
    {
        /** @var Collection<int, LivestreamSegment> $audibleSegments */
        $audibleSegments = $segments
            ->filter(fn (LivestreamSegment $segment): bool => in_array($segment->classification, [LivestreamSegmentClassification::Song, LivestreamSegmentClassification::Speech], true))
            ->values();

        /** @var Collection<int, LivestreamSegment> $speechSegments */
        $speechSegments = $audibleSegments
            ->filter(fn (LivestreamSegment $segment): bool => $segment->classification === LivestreamSegmentClassification::Speech)
            ->values();

        $sermonEvaluation = $this->sermonConfidenceService->evaluate($speechSegments);
        $sermonSegment = $sermonEvaluation['candidate'];
        $sermonSegmentId = $sermonSegment?->id;
        $sections = [];

        foreach ($audibleSegments as $index => $segment) {
            $sectionOrder = $index + 1;

            if ($segment->classification === LivestreamSegmentClassification::Song) {
                $sections[] = $this->makeAudioOnlySection(
                    $segment,
                    $sectionOrder,
                    ServiceSectionType::Song,
                    'low',
                    true,
                    'audio_only_song_segment'
                );

                continue;
            }

            if ($sermonSegmentId !== null && $segment->id === $sermonSegmentId) {
                $sections[] = $this->makeAudioOnlySection(
                    $segment,
                    $sectionOrder,
                    ServiceSectionType::Sermon,
                    'high',
                    false,
                    null,
                    ['sermon_detection_strategy' => 'single_dominant_speech_block']
                );

                continue;
            }

            $sections[] = $this->makeAudioOnlySection(
                $segment,
                $sectionOrder,
                ServiceSectionType::Other,
                'low',
                true,
                $sermonSegmentId === null ? 'no_high_confidence_sermon_candidate' : 'audio_only_speech_segment'
            );
        }

        return $sections;
    }

    /**
     * Helper to create a standardized classified section array from a segment.
     *
     * @param  'high'|'low'|'none'  $confidenceLevel
     * @param  array<string, mixed>  $extraMetadata
     * @return ClassifiedSection
     */
    private function makeAudioOnlySection(
        LivestreamSegment $segment,
        int $sectionOrder,
        ServiceSectionType $sectionType,
        string $confidenceLevel,
        bool $needsManualReview,
        ?string $reviewReason,
        array $extraMetadata = []
    ): array {
        $metadata = array_merge([
            'confidence_level' => $confidenceLevel,
            'classification_mode' => 'audio_only',
            'detected_segment_class' => $segment->classification->value,
        ], $extraMetadata);

        if ($reviewReason !== null) {
            $metadata['review_reason'] = $reviewReason;
        }

        return [
            'church_service_item_id' => null,
            'section_type' => $sectionType->value,
            'section_order' => $sectionOrder,
            'title' => null,
            'start_time' => (float) $segment->start_time,
            'end_time' => (float) $segment->end_time,
            'duration' => (float) $segment->duration,
            'confidence' => ServiceSectionConfidence::scoreForLevel($confidenceLevel),
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => $needsManualReview,
            'source_segment_ids' => [$segment->id],
            'metadata' => $metadata,
        ];
    }
}
