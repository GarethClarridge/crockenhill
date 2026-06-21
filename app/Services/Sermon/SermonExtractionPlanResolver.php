<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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

        $sermonSection = $this->findPreferredSection($processingLog, ServiceSectionType::Sermon);
        if (! $sermonSection instanceof ServiceSection) {
            return $this->baselinePlan($processingLog, ['reason' => 'no_high_confidence_sermon_section']);
        }

        // A sermon section longer than the plausible ceiling signals under-segmentation (F10) —
        // e.g. RMS collapsing a whole service into one block. Decline it so the run falls through
        // to the baseline path, where SermonCandidateConfidenceService routes it to manual review
        // rather than silently extracting the wrong content.
        $maxSermonDuration = (float) config('media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds', 2700);
        if ($maxSermonDuration > 0.0 && (float) $sermonSection->duration > $maxSermonDuration) {
            return $this->baselinePlan($processingLog, [
                'reason' => 'sermon_section_exceeds_maximum_duration',
                'sermon_section_id' => $sermonSection->id,
                'sermon_duration_seconds' => (float) $sermonSection->duration,
                'max_sermon_duration_seconds' => $maxSermonDuration,
            ]);
        }

        $bibleSection = $this->selectBibleReading($processingLog, $sermonSection);
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

        // A reading further than the maximum pairing gap is almost never the preached text
        // (F3) — e.g. an opening-notices verse 30+ minutes before the sermon. Do not pair it.
        $maxPairingGapSeconds = (float) config('media-processing.section_extraction.enhanced_sermon.max_pairing_gap_seconds', 900);
        if ($gapSeconds > $maxPairingGapSeconds) {
            return [
                'mode' => 'single_span',
                'source' => 'service_sections',
                'segments' => [[
                    'start_time' => (float) $sermonSection->start_time,
                    'end_time' => (float) $sermonSection->end_time,
                ]],
                'metadata' => [
                    'strategy' => 'sermon_only_reading_gap_exceeded',
                    'sermon_section_id' => $sermonSection->id,
                    'bible_section_id' => $bibleSection->id,
                    'gap_seconds' => $gapSeconds,
                    'max_pairing_gap_seconds' => $maxPairingGapSeconds,
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

    /**
     * Select the bible reading most likely to be the preached text, ranked by evidence rather
     * than mere service order (F5). Overlapping readings are still returned when they are the
     * best candidate, so the caller's invalid-timing branch can handle them — the selection
     * must not silently remove that branch.
     */
    private function selectBibleReading(MediaProcessingLog $processingLog, ServiceSection $sermonSection): ?ServiceSection
    {
        /** @var EloquentCollection<int, ServiceSection> $candidates */
        $candidates = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_type', ServiceSectionType::BibleReading->value)
            ->where('status', ServiceSectionStatus::Identified->value)
            ->where('needs_manual_review', false)
            ->where('confidence', '>=', ServiceSectionConfidence::HIGH_THRESHOLD)
            ->whereColumn('end_time', '>', 'start_time')
            ->with('churchServiceItem')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $sermonStart = (float) $sermonSection->start_time;
        $minReadingDuration = (float) config(
            'media-processing.section_extraction.enhanced_sermon.min_reading_duration_seconds',
            90
        );

        return $candidates
            ->sortByDesc(fn (ServiceSection $reading): array => $this->readingEvidence($reading, $sermonStart, $minReadingDuration))
            ->first();
    }

    /**
     * Evidence tuple for ranking a bible reading as the preached text, compared lexicographically
     * by sortByDesc so earlier elements dominate. Every element is "higher is better":
     * 1. linked to a `bibles`-type OoS item (the day's scripture is marked in the order of service);
     * 2. valid timing — ends before the sermon starts (an overlapping reading is worse);
     * 3. a substantive duration over a short preamble (F17, a demotion not a hard exclusion);
     * 4. proximity to the sermon; then longer duration and id as deterministic tie-breaks.
     *
     * @return array{int, int, int, float, float, int}
     */
    private function readingEvidence(ServiceSection $reading, float $sermonStart, float $minReadingDuration): array
    {
        $gap = $sermonStart - (float) $reading->end_time;
        $item = $reading->churchServiceItem;
        $biblesLinked = $item instanceof ChurchServiceItem && $item->type === 'bibles';

        return [
            $biblesLinked ? 1 : 0,
            $gap >= 0.0 ? 1 : 0,
            (float) $reading->duration >= $minReadingDuration ? 1 : 0,
            -abs($gap),
            (float) $reading->duration,
            (int) $reading->id,
        ];
    }
}
