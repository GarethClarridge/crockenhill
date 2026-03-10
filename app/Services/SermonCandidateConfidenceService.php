<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LivestreamSegmentClassification;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Collection;

class SermonCandidateConfidenceService
{
    /**
     * @param  Collection<int, LivestreamSegment>  $speechSegments
     * @return array{
     *     is_clear: bool,
     *     reason: 'clear'|'no_qualifying_speech_block'|'multiple_qualifying_speech_blocks'|'ratio_below_threshold',
     *     candidate: LivestreamSegment|null,
     *     qualifying_segments_count: int,
     *     next_longest_duration: float,
     *     speech_segments: array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>
     * }
     */
    public function evaluate(Collection $speechSegments): array
    {
        /** @var Collection<int, LivestreamSegment> $orderedByDuration */
        $orderedByDuration = $speechSegments
            ->sortByDesc(fn (LivestreamSegment $segment): float => (float) $segment->duration)
            ->values();

        /** @var Collection<int, LivestreamSegment> $qualifyingSegments */
        $qualifyingSegments = $orderedByDuration
            ->filter(fn (LivestreamSegment $segment): bool => (float) $segment->duration >= 1200.0)
            ->values();

        $speechSegmentSummaries = $speechSegments
            ->sortBy(fn (LivestreamSegment $segment): float => (float) $segment->start_time)
            ->values()
            ->map(fn (LivestreamSegment $segment): array => [
                'segment_id' => $segment->id,
                'start_time' => (float) $segment->start_time,
                'end_time' => (float) $segment->end_time,
                'duration' => (float) $segment->duration,
            ])
            ->all();

        /** @var LivestreamSegment|null $nextLongestSegment */
        $nextLongestSegment = $orderedByDuration->skip(1)->first();
        $nextLongestDuration = $nextLongestSegment instanceof LivestreamSegment
            ? (float) $nextLongestSegment->duration
            : 0.0;

        if ($qualifyingSegments->count() === 0) {
            return [
                'is_clear' => false,
                'reason' => 'no_qualifying_speech_block',
                'candidate' => null,
                'qualifying_segments_count' => 0,
                'next_longest_duration' => $nextLongestDuration,
                'speech_segments' => $speechSegmentSummaries,
            ];
        }

        if ($qualifyingSegments->count() > 1) {
            return [
                'is_clear' => false,
                'reason' => 'multiple_qualifying_speech_blocks',
                'candidate' => null,
                'qualifying_segments_count' => $qualifyingSegments->count(),
                'next_longest_duration' => $nextLongestDuration,
                'speech_segments' => $speechSegmentSummaries,
            ];
        }

        $candidate = $qualifyingSegments->first();

        if (! $candidate instanceof LivestreamSegment) {
            return [
                'is_clear' => false,
                'reason' => 'no_qualifying_speech_block',
                'candidate' => null,
                'qualifying_segments_count' => 0,
                'next_longest_duration' => 0.0,
                'speech_segments' => $speechSegmentSummaries,
            ];
        }

        if ($nextLongestDuration > 0.0 && (float) $candidate->duration < ($nextLongestDuration * 1.5)) {
            return [
                'is_clear' => false,
                'reason' => 'ratio_below_threshold',
                'candidate' => null,
                'qualifying_segments_count' => 1,
                'next_longest_duration' => $nextLongestDuration,
                'speech_segments' => $speechSegmentSummaries,
            ];
        }

        return [
            'is_clear' => true,
            'reason' => 'clear',
            'candidate' => $candidate,
            'qualifying_segments_count' => 1,
            'next_longest_duration' => $nextLongestDuration,
            'speech_segments' => $speechSegmentSummaries,
        ];
    }

    /**
     * @return array{
     *     is_clear: bool,
     *     reason: 'clear'|'no_qualifying_speech_block'|'multiple_qualifying_speech_blocks'|'ratio_below_threshold',
     *     candidate: LivestreamSegment|null,
     *     qualifying_segments_count: int,
     *     next_longest_duration: float,
     *     speech_segments: array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>
     * }
     */
    public function evaluateForProcessingLog(MediaProcessingLog $processingLog): array
    {
        /** @var Collection<int, LivestreamSegment> $speechSegments */
        $speechSegments = LivestreamSegment::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('classification', LivestreamSegmentClassification::Speech->value)
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        return $this->evaluate($speechSegments);
    }
}
