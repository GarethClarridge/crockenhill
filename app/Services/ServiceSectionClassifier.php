<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LivestreamSegmentClassification;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Support\ServiceSectionConfidence;
use Illuminate\Support\Collection;

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
     * @return array{
     *     skipped: bool,
     *     skip_reason: ?string,
     *     sections: array<int, array{
     *         church_service_item_id: int|null,
     *         section_type: string,
     *         section_order: int,
     *         title: ?string,
     *         start_time: float,
     *         end_time: float,
     *         duration: float,
     *         confidence: float,
     *         status: string,
     *         needs_manual_review: bool,
     *         source_segment_ids: array<int, int>,
     *         metadata: array<string, mixed>
     *     }>
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
     * @param  Collection<int, LivestreamSegment>  $segments
     * @return array<int, array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }>
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
                    ServiceSectionType::SONG,
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
                    ServiceSectionType::SERMON,
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
                ServiceSectionType::OTHER,
                'low',
                true,
                $sermonSegmentId === null ? 'no_high_confidence_sermon_candidate' : 'audio_only_speech_segment'
            );
        }

        return $sections;
    }

    /**
     * @param  'high'|'low'|'none'  $confidenceLevel
     * @param  array<string, mixed>  $extraMetadata
     * @return array{
     *     church_service_item_id: null,
     *     section_type: string,
     *     section_order: int,
     *     title: null,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }
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
