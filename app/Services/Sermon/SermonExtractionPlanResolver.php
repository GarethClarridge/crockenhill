<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;

class SermonExtractionPlanResolver
{
    /**
     * @return array{
     *     mode: 'single_span'|'concat_spans'|'baseline',
     *     source: 'service_sections'|'processing_log'|'manual_review',
     *     segments: array<int, array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }
     */
    public function resolve(MediaProcessingLog $processingLog): array
    {
        $confirmedSegmentId = $processingLog->manuallyConfirmedSegmentId();
        if ($confirmedSegmentId !== null) {
            return $this->confirmedSegmentPlan($processingLog, $confirmedSegmentId);
        }

        $legacySectionPreference = (bool) config(
            'media-processing.section_classification.prefer_high_confidence_sermon_section',
            true
        );

        if (! $legacySectionPreference) {
            return $this->baselinePlan($processingLog, ['reason' => 'legacy_section_preference_disabled']);
        }

        if (! (bool) config('media-processing.section_extraction.enhanced_sermon.enabled', true)) {
            return $this->baselinePlan($processingLog, ['reason' => 'enhanced_sermon_disabled']);
        }

        $sermonSection = $this->findPreferredSection($processingLog, ServiceSectionType::SERMON);
        if (! $sermonSection instanceof ServiceSection) {
            return $this->baselinePlan($processingLog, ['reason' => 'no_high_confidence_sermon_section']);
        }

        $bibleSection = $this->findPreferredSection($processingLog, ServiceSectionType::BIBLE_READING);
        if (! $bibleSection instanceof ServiceSection) {
            return [
                'mode' => 'single_span',
                'source' => 'service_sections',
                'segments' => [[
                    'start_time' => (float) $sermonSection->start_time,
                    'end_time' => (float) $sermonSection->end_time,
                ]],
                'metadata' => [
                    'strategy' => 'sermon_only',
                    'sermon_section_id' => $sermonSection->id,
                ],
            ];
        }

        $adjacentGapSeconds = (float) config('media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds', 60);
        $gapSeconds = (float) $sermonSection->start_time - (float) $bibleSection->end_time;

        if ($gapSeconds < 0.0) {
            return [
                'mode' => 'single_span',
                'source' => 'service_sections',
                'segments' => [[
                    'start_time' => (float) $sermonSection->start_time,
                    'end_time' => (float) $sermonSection->end_time,
                ]],
                'metadata' => [
                    'strategy' => 'sermon_only_invalid_bible_timing',
                    'sermon_section_id' => $sermonSection->id,
                    'bible_section_id' => $bibleSection->id,
                    'gap_seconds' => $gapSeconds,
                ],
            ];
        }

        if ($gapSeconds >= 0.0 && $gapSeconds <= $adjacentGapSeconds) {
            return [
                'mode' => 'single_span',
                'source' => 'service_sections',
                'segments' => [[
                    'start_time' => (float) $bibleSection->start_time,
                    'end_time' => (float) $sermonSection->end_time,
                ]],
                'metadata' => [
                    'strategy' => 'adjacent_bible_plus_sermon',
                    'sermon_section_id' => $sermonSection->id,
                    'bible_section_id' => $bibleSection->id,
                    'gap_seconds' => $gapSeconds,
                ],
            ];
        }

        if (
            $gapSeconds > $adjacentGapSeconds
            && (bool) config('media-processing.section_extraction.enhanced_sermon.allow_non_adjacent_concat', true)
        ) {
            return [
                'mode' => 'concat_spans',
                'source' => 'service_sections',
                'segments' => [
                    [
                        'start_time' => (float) $bibleSection->start_time,
                        'end_time' => (float) $bibleSection->end_time,
                    ],
                    [
                        'start_time' => (float) $sermonSection->start_time,
                        'end_time' => (float) $sermonSection->end_time,
                    ],
                ],
                'metadata' => [
                    'strategy' => 'non_adjacent_bible_plus_sermon_concat',
                    'sermon_section_id' => $sermonSection->id,
                    'bible_section_id' => $bibleSection->id,
                    'gap_seconds' => $gapSeconds,
                ],
            ];
        }

        return [
            'mode' => 'single_span',
            'source' => 'service_sections',
            'segments' => [[
                'start_time' => (float) $sermonSection->start_time,
                'end_time' => (float) $sermonSection->end_time,
            ]],
            'metadata' => [
                'strategy' => 'sermon_only_concat_disabled',
                'sermon_section_id' => $sermonSection->id,
                'bible_section_id' => $bibleSection->id,
                'gap_seconds' => $gapSeconds,
            ],
        ];
    }

    /**
     * @return array{
     *     mode: 'single_span',
     *     source: 'manual_review',
     *     segments: array<int, array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }
     */
    private function confirmedSegmentPlan(MediaProcessingLog $processingLog, int $segmentId): array
    {
        $segment = $processingLog->segments()->find($segmentId);

        if (! $segment instanceof LivestreamSegment) {
            throw new \Exception("Manually confirmed segment {$segmentId} not found on processing log");
        }

        return [
            'mode' => 'single_span',
            'source' => 'manual_review',
            'segments' => [[
                'start_time' => (float) $segment->start_time,
                'end_time' => (float) $segment->end_time,
            ]],
            'metadata' => [
                'strategy' => 'manual_review_confirmed_segment',
                'sermon_segment_id' => $segment->id,
                'manual_confirmation' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     mode: 'baseline',
     *     source: 'processing_log',
     *     segments: array<int, array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }
     */
    private function baselinePlan(MediaProcessingLog $processingLog, array $metadata): array
    {
        $baselineStart = $processingLog->sermon_start_time;
        $baselineEnd = $processingLog->sermon_end_time;

        if (! is_float($baselineStart) || ! is_float($baselineEnd) || $baselineEnd <= $baselineStart) {
            throw new \Exception('Sermon segment times not found in processing log');
        }

        return [
            'mode' => 'baseline',
            'source' => 'processing_log',
            'segments' => [[
                'start_time' => $baselineStart,
                'end_time' => $baselineEnd,
            ]],
            'metadata' => $metadata,
        ];
    }

    private function findPreferredSection(MediaProcessingLog $processingLog, ServiceSectionType $type): ?ServiceSection
    {
        $section = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_type', $type->value)
            ->where('status', ServiceSectionStatus::Identified->value)
            ->where('needs_manual_review', false)
            ->where('confidence', '>=', ServiceSectionConfidence::HIGH_THRESHOLD)
            ->whereColumn('end_time', '>', 'start_time')
            ->orderBy('section_order')
            ->orderByDesc('duration')
            ->orderByDesc('id')
            ->first();

        return $section instanceof ServiceSection ? $section : null;
    }
}
