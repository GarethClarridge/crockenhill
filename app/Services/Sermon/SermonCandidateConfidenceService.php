<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\LivestreamSegmentClassification;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Collection;

/**
 * Evaluates speech segments to identify the primary sermon candidate.
 *
 * This service applies duration-based heuristics to a collection of speech
 * segments (typically derived from RMS analysis) to determine which segment
 * represents the main sermon. It considers minimum/maximum duration limits
 * and the relative dominance of the longest segment to provide a high-confidence
 * automated selection.
 *
 * @phpstan-type SermonCandidateEvaluation array{
 *     is_clear: bool,
 *     reason: 'clear'|'no_qualifying_speech_block'|'multiple_qualifying_speech_blocks'|'ratio_below_threshold'|'candidate_exceeds_maximum_duration',
 *     candidate: LivestreamSegment|null,
 *     qualifying_segments_count: int,
 *     next_longest_duration: float,
 *     speech_segments: array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>
 * }
 */
class SermonCandidateConfidenceService
{
    /**
     * Identify the most likely sermon segment from a collection of speech segments.
     *
     * Evaluates candidates based on three core heuristics:
     * 1. Minimum duration: The segment must be at least 20 minutes (1200s) long.
     * 2. Maximum duration: The segment must not exceed the configured limit (default 45 mins / 2700s).
     * 3. Dominance ratio: The candidate must be at least 1.5x longer than the next-longest segment.
     *
     * @param  Collection<int, LivestreamSegment>  $speechSegments
     * @return SermonCandidateEvaluation
     */
    public function evaluate(Collection $speechSegments): array
    {
        /** @var Collection<int, LivestreamSegment> $orderedByDuration */
        $orderedByDuration = $speechSegments
            ->sortByDesc(fn (LivestreamSegment $segment): float => (float) $segment->duration)
            ->values();

        /**
         * Filter segments that meet the minimum sermon duration threshold.
         *
         * Default threshold is 20 minutes (1200.0 seconds). This is calibrated to
         * distinguish main sermons from other speech elements like notices,
         * prayers, or readings, which are typically shorter in this context.
         *
         * @var Collection<int, LivestreamSegment> $qualifyingSegments
         */
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

        if ($qualifyingSegments->isEmpty()) {
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

        if ($candidate === null) {
            return [
                'is_clear' => false,
                'reason' => 'no_qualifying_speech_block',
                'candidate' => null,
                'qualifying_segments_count' => 0,
                'next_longest_duration' => 0.0,
                'speech_segments' => $speechSegmentSummaries,
            ];
        }

        // An over-long sole candidate signals under-segmentation (F10): RMS collapsed several
        // service elements into one block. The 1.5x ratio guard cannot fire (there is no
        // competing segment), so cap the duration explicitly and route to manual review.
        $maxCandidateDuration = (float) config(
            'media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds',
            2700
        );

        if ($maxCandidateDuration > 0.0 && (float) $candidate->duration > $maxCandidateDuration) {
            return [
                'is_clear' => false,
                'reason' => 'candidate_exceeds_maximum_duration',
                'candidate' => null,
                'qualifying_segments_count' => 1,
                'next_longest_duration' => $nextLongestDuration,
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
     * Fetch speech segments for a given processing log and evaluate them for a sermon candidate.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to evaluate
     * @return SermonCandidateEvaluation
     */
    public function evaluateForProcessingLog(MediaProcessingLog $processingLog): array
    {
        /** @var Collection<int, LivestreamSegment> $speechSegments */
        $speechSegments = LivestreamSegment::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('classification', LivestreamSegmentClassification::Speech)
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        return $this->evaluate($speechSegments);
    }
}
