<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
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
     *         status: string,
     *         needs_manual_review: bool,
     *         source_segment_ids: array<int, int>,
     *         metadata: array<string, mixed>
     *     }>
     * }
     */
    public function classify(MediaProcessingLog $processingLog): array
    {
        $churchService = $this->resolveChurchService($processingLog);

        /** @var Collection<int, LivestreamSegment> $segments */
        $segments = LivestreamSegment::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('segment_order')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        if (! $churchService instanceof ChurchService) {
            return [
                'skipped' => false,
                'skip_reason' => null,
                'sections' => $this->classifyFromAudioOnlySegments($segments),
            ];
        }

        /** @var Collection<int, ChurchServiceItem> $serviceItems */
        $serviceItems = $churchService->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return [
            'skipped' => false,
            'skip_reason' => null,
            'sections' => $this->classifyAgainstChurchService($segments, $serviceItems),
        ];
    }

    /**
     * @param  Collection<int, LivestreamSegment>  $segments
     * @param  Collection<int, ChurchServiceItem>  $serviceItems
     * @return array<int, array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }>
     */
    private function classifyAgainstChurchService(Collection $segments, Collection $serviceItems): array
    {
        $usedSegmentIds = [];
        $segmentPointer = 0;
        $sections = [];
        $previousMatchedSegment = null;

        foreach ($serviceItems as $item) {
            $expectedSegmentClass = $this->expectedSegmentClassForItemType($item->type);
            $matchedByExpectedClass = $this->findNextSegmentByClassification(
                $segments,
                $segmentPointer,
                $expectedSegmentClass,
                $usedSegmentIds
            );

            $matchedSegment = $matchedByExpectedClass['segment'];
            $matchedIndex = $matchedByExpectedClass['index'];
            $confidenceLevel = 'none';
            $reviewReason = 'no_segment_available';
            $status = ServiceSectionStatus::SKIPPED;
            $needsManualReview = true;

            if ($matchedSegment instanceof LivestreamSegment && is_int($matchedIndex)) {
                $confidenceLevel = 'high';
                $reviewReason = '';
                $status = ServiceSectionStatus::IDENTIFIED;
                $needsManualReview = false;
            } else {
                $fallbackMatch = $this->findNextAnySegment($segments, $segmentPointer, $usedSegmentIds);
                $matchedSegment = $fallbackMatch['segment'];
                $matchedIndex = $fallbackMatch['index'];

                if ($matchedSegment instanceof LivestreamSegment && is_int($matchedIndex)) {
                    $confidenceLevel = 'low';
                    $reviewReason = 'expected_type_mismatch';
                    $status = ServiceSectionStatus::IDENTIFIED;
                    $needsManualReview = true;
                }
            }

            if ($matchedSegment instanceof LivestreamSegment && is_int($matchedIndex)) {
                $usedSegmentIds[] = $matchedSegment->id;
                $segmentPointer = $matchedIndex + 1;

                $startTime = (float) $matchedSegment->start_time;
                $endTime = (float) $matchedSegment->end_time;
                $duration = (float) $matchedSegment->duration;
                $sourceSegmentIds = [$matchedSegment->id];
                $matchedSegmentClass = $matchedSegment->classification;
            } else {
                $startTime = 0.0;
                $endTime = 0.0;
                $duration = 0.0;
                $sourceSegmentIds = [];
                $matchedSegmentClass = null;
            }

            $anomalies = $this->detectAnomalies($previousMatchedSegment, $matchedSegment);
            if ($anomalies !== []) {
                $needsManualReview = true;

                if ($confidenceLevel === 'high') {
                    $confidenceLevel = 'low';
                }

                $reviewReason = 'segment_overlap_or_order_anomaly';
            }

            $metadata = [
                'confidence_level' => $confidenceLevel,
                'classification_mode' => 'openlp_aligned',
                'expected_segment_class' => $expectedSegmentClass,
            ];

            if ($matchedSegmentClass !== null) {
                $metadata['matched_segment_class'] = $matchedSegmentClass;
            }

            if ($reviewReason !== '') {
                $metadata['review_reason'] = $reviewReason;
            }

            if ($anomalies !== []) {
                $metadata['anomalies'] = $anomalies;
            }

            $sections[] = [
                'church_service_item_id' => $item->id,
                'section_type' => $this->sectionTypeFromItem($item)->value,
                'section_order' => $item->position,
                'title' => $item->title,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'status' => $status->value,
                'needs_manual_review' => $needsManualReview,
                'source_segment_ids' => $sourceSegmentIds,
                'metadata' => $metadata,
            ];

            if ($matchedSegment instanceof LivestreamSegment) {
                $previousMatchedSegment = $matchedSegment;
            }
        }

        return $sections;
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

    private function expectedSegmentClassForItemType(string $itemType): string
    {
        return strtolower($itemType) === 'songs' ? 'song' : 'speech';
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
            ->filter(fn (LivestreamSegment $segment): bool => in_array($segment->classification, ['song', 'speech'], true))
            ->values();

        /** @var Collection<int, LivestreamSegment> $speechSegments */
        $speechSegments = $audibleSegments
            ->filter(fn (LivestreamSegment $segment): bool => $segment->classification === 'speech')
            ->values();

        $sermonEvaluation = $this->sermonConfidenceService->evaluate($speechSegments);
        $sermonSegment = $sermonEvaluation['candidate'];
        $sermonSegmentId = $sermonSegment?->id;
        $sections = [];

        foreach ($audibleSegments as $index => $segment) {
            $sectionOrder = $index + 1;

            if ($segment->classification === 'song') {
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
            'detected_segment_class' => $segment->classification,
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
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => $needsManualReview,
            'source_segment_ids' => [$segment->id],
            'metadata' => $metadata,
        ];
    }

    private function sectionTypeFromItem(ChurchServiceItem $item): ServiceSectionType
    {
        $metadataSectionType = $item->metadata['section_type'] ?? null;

        if (is_string($metadataSectionType)) {
            $resolved = ServiceSectionType::tryFrom($metadataSectionType);

            if ($resolved instanceof ServiceSectionType) {
                return $resolved;
            }
        }

        $itemType = strtolower($item->type);

        if ($itemType === 'songs') {
            return ServiceSectionType::SONG;
        }

        if ($itemType === 'bibles') {
            return ServiceSectionType::BIBLE_READING;
        }

        $title = strtolower($item->title);

        if (str_contains($title, 'children')) {
            return ServiceSectionType::CHILDRENS_TALK;
        }

        if (str_contains($title, 'prayer')) {
            return ServiceSectionType::PRAYER;
        }

        if (str_contains($title, 'notice') || str_contains($title, 'announcement')) {
            return ServiceSectionType::NOTICES;
        }

        if (str_contains($title, 'welcome')) {
            return ServiceSectionType::WELCOME;
        }

        if (str_contains($title, 'sermon') || str_contains($title, 'message')) {
            return ServiceSectionType::SERMON;
        }

        return ServiceSectionType::OTHER;
    }

    /**
     * @param  Collection<int, LivestreamSegment>  $segments
     * @param  array<int, int>  $usedSegmentIds
     * @return array{segment: LivestreamSegment|null, index: int|null}
     */
    private function findNextSegmentByClassification(
        Collection $segments,
        int $startIndex,
        string $classification,
        array $usedSegmentIds
    ): array {
        for ($index = $startIndex; $index < $segments->count(); $index++) {
            $segment = $segments->get($index);
            if (! $segment instanceof LivestreamSegment) {
                continue;
            }

            if (in_array($segment->id, $usedSegmentIds, true)) {
                continue;
            }

            if ($segment->classification !== $classification) {
                continue;
            }

            return ['segment' => $segment, 'index' => $index];
        }

        return ['segment' => null, 'index' => null];
    }

    /**
     * @param  Collection<int, LivestreamSegment>  $segments
     * @param  array<int, int>  $usedSegmentIds
     * @return array{segment: LivestreamSegment|null, index: int|null}
     */
    private function findNextAnySegment(Collection $segments, int $startIndex, array $usedSegmentIds): array
    {
        for ($index = $startIndex; $index < $segments->count(); $index++) {
            $segment = $segments->get($index);
            if (! $segment instanceof LivestreamSegment) {
                continue;
            }

            if (in_array($segment->id, $usedSegmentIds, true)) {
                continue;
            }

            return ['segment' => $segment, 'index' => $index];
        }

        return ['segment' => null, 'index' => null];
    }

    /**
     * @return array<int, string>
     */
    private function detectAnomalies(?LivestreamSegment $previousSegment, ?LivestreamSegment $currentSegment): array
    {
        if (
            ! $previousSegment instanceof LivestreamSegment
            || ! $currentSegment instanceof LivestreamSegment
        ) {
            return [];
        }

        $anomalies = [];
        $previousEnd = (float) $previousSegment->end_time;
        $currentStart = (float) $currentSegment->start_time;

        if ($currentStart < $previousEnd) {
            $anomalies[] = 'segment_overlap_detected';
        }

        $previousOrder = $previousSegment->segment_order;
        $currentOrder = $currentSegment->segment_order;

        if (
            is_int($previousOrder)
            && is_int($currentOrder)
            && $currentOrder <= $previousOrder
        ) {
            $anomalies[] = 'segment_order_anomaly_detected';
        }

        return $anomalies;
    }
}
