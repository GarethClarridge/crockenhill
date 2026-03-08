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

    public function __construct(
        ?MediaProcessingIdentityResolver $identityResolver = null
    ) {
        $this->identityResolver = $identityResolver ?? new MediaProcessingIdentityResolver;
    }

    /**
     * @return array{
     *     skipped: bool,
     *     skip_reason: ?string,
     *     sections: array<int, array{
     *         church_service_item_id: int,
     *         section_type: string,
     *         section_order: int,
     *         title: ?string,
     *         start_time: float,
     *         end_time: float,
     *         duration: float,
     *         status: string,
     *         needs_manual_review: bool,
     *         source_segment_ids: array<int, int>,
     *         metadata: array{
     *             confidence_level: 'high'|'low'|'none',
     *             classification_mode: 'openlp_aligned',
     *             expected_segment_class: string,
     *             matched_segment_class?: string,
     *             review_reason?: string,
     *             anomalies?: array<int, string>
     *         }
     *     }>
     * }
     */
    public function classify(MediaProcessingLog $processingLog): array
    {
        $churchService = $this->resolveChurchService($processingLog);
        $requireMatchingService = (bool) config('media-processing.section_classification.require_matching_church_service', true);

        if (! $churchService instanceof ChurchService) {
            if (! $requireMatchingService) {
                return [
                    'skipped' => false,
                    'skip_reason' => null,
                    'sections' => [],
                ];
            }

            return [
                'skipped' => true,
                'skip_reason' => 'no_matching_church_service',
                'sections' => [],
            ];
        }

        /** @var Collection<int, LivestreamSegment> $segments */
        $segments = LivestreamSegment::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('segment_order')
            ->orderBy('start_time')
            ->get();

        /** @var Collection<int, ChurchServiceItem> $serviceItems */
        $serviceItems = $churchService->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

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

        return [
            'skipped' => false,
            'skip_reason' => null,
            'sections' => $sections,
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

    private function expectedSegmentClassForItemType(string $itemType): string
    {
        return strtolower($itemType) === 'songs' ? 'song' : 'speech';
    }

    private function sectionTypeFromItem(ChurchServiceItem $item): ServiceSectionType
    {
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
