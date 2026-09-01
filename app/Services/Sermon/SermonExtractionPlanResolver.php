<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Support\SermonAutoExtractionPolicy;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SermonExtractionPlanResolver
{
    /**
     * Section types that may form the sermon's conclusion when they follow it.
     *
     * A song is deliberately absent: it is the boundary, never part of the sermon.
     * So are welcome/notices/children's talk, which are separate service elements
     * wherever they fall.
     *
     * @var list<ServiceSectionType>
     */
    private const SERMON_TRAILING_TYPES = [
        ServiceSectionType::Prayer,
        ServiceSectionType::BibleReading,
        ServiceSectionType::Other,
    ];

    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferences,
    ) {}

    /**
     * Resolve the optimal sermon extraction plan from a livestream recording.
     *
     * This method implements a multi-layered decision hierarchy to determine exactly which
     * segments of a livestream recording should be extracted as the sermon. It prioritizes
     * high-precision markers before falling back to coarser baseline data.
     *
     * Decision Hierarchy:
     * 1. Manual Confirmation: If a human has explicitly selected a segment in the UI, that
     *    selection always wins.
     * 2. Enhanced Extraction: If the "enhanced sermon" feature is enabled, the resolver
     *    looks for high-confidence sermon sections identified during pipeline processing.
     *    It attempts to pair the sermon with a preceding Bible reading section.
     * 3. Baseline: Fall back to the coarse start/end times originally detected during the
     *    initial media processing pass.
     *
     * Extraction Modes:
     * - 'single_span': Extracts one contiguous block of audio/video. This is used for
     *   manual confirmations, baseline fallbacks, or when a sermon and its reading are
     *   adjacent (gap < configured threshold).
     * - 'concat_spans': Extracts multiple non-contiguous segments and joins them. This is
     *   used when a Bible reading and sermon are separated by a gap (e.g., a short song or
     *   prayer) but should still be presented as a single media item.
     * - 'baseline': A special mode indicating the resolver fell through to original
     *   processing log times.
     *
     * Logic Constraints and Failure Modes:
     * - F10 (Under-segmentation): If a sermon section exceeds a plausible maximum duration,
     *   it is rejected as it likely includes multiple service elements that weren't correctly
     *   split by RMS analysis.
     * - F3 (Pairing Gap): Bible readings too far removed from the sermon (exceeding
     *   max_pairing_gap_seconds) are not paired to avoid including irrelevant readings.
     * - F5/F17 (Reading Evidence): Readings are ranked by evidence (linkage to OoS items,
     *   substantive duration, proximity) rather than simple order to find the most likely
     *   preached text.
     *
     * @param  MediaProcessingLog  $processingLog  The log of the current processing run
     * @return array{
     *     mode: 'single_span'|'concat_spans'|'baseline',
     *     source: 'service_sections'|'processing_log'|'manual_review',
     *     segments: array<int, array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }
     *
     * @throws \Exception When baseline times are missing or confirmed segments cannot be found.
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

        // A sermon runs to the next song in all but a handful of services, and the
        // sections in between — a closing prayer above all — are the sermon's
        // conclusion, not separate elements. Resolve the published end before any
        // plan is built so every branch below publishes the same span.
        [$sermonEnd, $trailingSectionIds, $sermonBoundaryEvidence] = $this->resolveSermonEnd($processingLog, $sermonSection);
        $sermonMetadata = [
            'sermon_section_id' => $sermonSection->id,
            'trailing_section_ids' => $trailingSectionIds,
            'sermon_boundary' => $sermonBoundaryEvidence,
        ];

        $bibleSection = $this->selectBibleReading($processingLog, $sermonSection);
        if (! $bibleSection instanceof ServiceSection) {
            return [
                'mode' => 'single_span',
                'source' => 'service_sections',
                'segments' => [[
                    'start_time' => (float) $sermonSection->start_time,
                    'end_time' => $sermonEnd,
                ]],
                'metadata' => [
                    'strategy' => 'sermon_only',
                    ...$sermonMetadata,
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
                    'end_time' => $sermonEnd,
                ]],
                'metadata' => [
                    'strategy' => 'sermon_only_invalid_bible_timing',
                    ...$sermonMetadata,
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
                    'end_time' => $sermonEnd,
                ]],
                'metadata' => [
                    'strategy' => 'adjacent_bible_plus_sermon',
                    ...$sermonMetadata,
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
                    'end_time' => $sermonEnd,
                ]],
                'metadata' => [
                    'strategy' => 'sermon_only_reading_gap_exceeded',
                    ...$sermonMetadata,
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
                        'end_time' => $sermonEnd,
                    ],
                ],
                'metadata' => [
                    'strategy' => 'non_adjacent_bible_plus_sermon_concat',
                    ...$sermonMetadata,
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
                'end_time' => $sermonEnd,
            ]],
            'metadata' => [
                'strategy' => 'sermon_only_concat_disabled',
                ...$sermonMetadata,
                'bible_section_id' => $bibleSection->id,
                'gap_seconds' => $gapSeconds,
            ],
        ];
    }

    /**
     * The published sermon ends at the next song, not at the sermon section's own end.
     *
     * Sermon-to-prayer is continuous speech from one voice, which is the boundary the
     * detector places least reliably; a plan that depends on it being right is fragile,
     * and the failure is asymmetric — over-running gives a listener a topically
     * continuous closing prayer, while stopping short truncates the sermon mid-thought,
     * as the 2024-07-28 corpus run did by publishing to 2690s and losing the closing
     * appeal that ran to ~3055s.
     *
     * This is the forward mirror of the reading pairing above: the same
     * `adjacent_gap_seconds` guard, so a section beyond that gap is a separate element
     * and ends the span. A song always ends it. The result is refused if it would take
     * the sermon past the plausible ceiling, since that signals under-segmentation
     * rather than a long conclusion.
     *
     * @return array{0: float, 1: list<int>, 2: array<string, mixed>}
     */
    private function resolveSermonEnd(MediaProcessingLog $processingLog, ServiceSection $sermonSection): array
    {
        $sermonEnd = (float) $sermonSection->end_time;

        /** @var EloquentCollection<int, ServiceSection> $following */
        $following = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('start_time', '>=', $sermonSection->end_time)
            ->where('id', '!=', $sermonSection->id)
            ->whereColumn('end_time', '>', 'start_time')
            ->orderBy('start_time')
            ->get();

        /** @var EloquentCollection<int, ServiceSection> $overlapping */
        $overlapping = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('id', '!=', $sermonSection->id)
            ->where('start_time', '<', $sermonEnd)
            ->where('end_time', '>', $sermonEnd)
            ->whereColumn('end_time', '>', 'start_time')
            ->orderBy('start_time')
            ->get();

        $adjacentGapSeconds = (float) config('media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds', 60);
        $maxSermonDuration = (float) config('media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds', 2700);

        $absorbed = [];
        $cursor = $sermonEnd;
        $separateFollowing = null;

        foreach ($following as $section) {
            if (! in_array($section->section_type, self::SERMON_TRAILING_TYPES, true)) {
                $separateFollowing = $section;

                break;
            }

            if (((float) $section->start_time - $cursor) > $adjacentGapSeconds) {
                $separateFollowing = $section;

                break;
            }

            $cursor = (float) $section->end_time;
            $absorbed[] = $section;
        }

        $risks = [];

        if ($overlapping->isNotEmpty()) {
            $risks[] = [
                'kind' => 'sermon_boundary_conflicting_evidence',
                'detail' => 'Another timed service section crosses the detected sermon end, so the sermon boundary needs individual review.',
            ];
        }

        if (count($absorbed) > 1) {
            $risks[] = [
                'kind' => 'sermon_boundary_multiple_following_items',
                'detail' => sprintf(
                    '%d separately timed following sections were merged into the sermon candidate before the next independent item.',
                    count($absorbed),
                ),
            ];
        }

        $longTailThreshold = (float) config(
            'media-processing.section_extraction.enhanced_sermon.long_tail_review_seconds',
            120,
        );

        /**
         * Duration alone must never raise a review hold, so a long tail counts
         * only when a source other than this recording attests the absorbed
         * section as a service item in its own right. The mere existence of a
         * later section is not corroboration: nearly every service has a closing
         * song after the sermon, so testing for one would make this a pure
         * duration trigger under a corroboration-shaped name.
         */
        if (
            count($absorbed) === 1
            && $longTailThreshold > 0.0
            && ((float) $absorbed[0]->end_time - (float) $absorbed[0]->start_time) >= $longTailThreshold
            && $this->independentlyAttested($absorbed[0])
        ) {
            $risks[] = [
                'kind' => 'sermon_boundary_long_tail',
                'detail' => sprintf(
                    'A %.1fs following section was merged into the sermon, and a source other than this recording attests it as a separate service item; the sermon boundary needs review.',
                    (float) $absorbed[0]->end_time - (float) $absorbed[0]->start_time,
                ),
            ];
        }

        $ceilingApplied = false;

        if ($absorbed !== []
            && $maxSermonDuration > 0.0
            && ($cursor - (float) $sermonSection->start_time) > $maxSermonDuration
        ) {
            $cursor = $sermonEnd;
            $absorbed = [];
            $ceilingApplied = true;
        }

        $decision = $risks !== []
            ? 'review'
            : ($absorbed !== []
                ? ($separateFollowing instanceof ServiceSection && count($absorbed) === 1
                    ? 'retain_ambiguous_bridge'
                    : 'retain_inclusive')
                : ($separateFollowing instanceof ServiceSection ? 'stop_at_separate_item' : 'retain_inclusive'));

        return [
            $cursor,
            array_map(static fn (ServiceSection $section): int => $section->id, $absorbed),
            [
                'method' => 'timed_service_structure',
                'decision' => $decision,
                'requires_review' => $risks !== [],
                'sermon_section_id' => $sermonSection->id,
                'candidate_start_time' => (float) $sermonSection->start_time,
                'candidate_end_time' => $cursor,
                'absorbed_section_ids' => array_map(static fn (ServiceSection $section): int => $section->id, $absorbed),
                'separate_following_section_id' => $separateFollowing?->id,
                'overlapping_section_ids' => $overlapping->map(static fn (ServiceSection $section): int => $section->id)->values()->all(),
                'risks' => $risks,
                'ceiling_applied' => $ceilingApplied,
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

    /**
     * Whether the structure path already produced a high-confidence sermon
     * section this run could auto-extract from — i.e. a paused manual sermon
     * review is redundant and can be resolved without a human segment pick.
     */
    public function hasAutoExtractableSermonSection(MediaProcessingLog $processingLog): bool
    {
        return $this->findPreferredSection($processingLog, ServiceSectionType::Sermon) instanceof ServiceSection;
    }

    /**
     * Review-flagged sections are not excluded wholesale: ordering flags such
     * as a cross-type OoS inversion question item alignment, not boundaries,
     * so SermonAutoExtractionPolicy decides which review states still qualify.
     */
    private function findPreferredSection(MediaProcessingLog $processingLog, ServiceSectionType $type): ?ServiceSection
    {
        return ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_type', $type->value)
            ->where('status', ServiceSectionStatus::Identified->value)
            ->where('confidence', '>=', ServiceSectionConfidence::HIGH_THRESHOLD)
            ->whereColumn('end_time', '>', 'start_time')
            ->orderBy('section_order')
            ->orderByDesc('duration')
            ->orderByDesc('id')
            ->get()
            ->first(fn (ServiceSection $section): bool => SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction(
                (bool) $section->needs_manual_review,
                $this->reviewFlags($section),
            ));
    }

    /**
     * Whether a source other than this recording attests the section as a
     * service item of its own. An order of service or a service email naming
     * the item is the non-duration evidence a long tail needs before it can be
     * called a separate following item rather than a long conclusion.
     */
    private function independentlyAttested(ServiceSection $section): bool
    {
        $item = $section->churchServiceItem;

        if ($item === null) {
            return false;
        }

        foreach ($item->provenanceSources() as $source) {
            if ($source->value !== 'livestream') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function reviewFlags(ServiceSection $section): array
    {
        $flags = $section->metadata['review_flags'] ?? [];

        if (! is_array($flags)) {
            return [];
        }

        return array_values(array_filter($flags, 'is_string'));
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
        $sermonReference = $this->sermonReference($sermonSection);
        $minReadingDuration = (float) config(
            'media-processing.section_extraction.enhanced_sermon.min_reading_duration_seconds',
            90
        );

        return $candidates
            ->sortByDesc(fn (ServiceSection $reading): array => $this->readingEvidence($reading, $sermonStart, $sermonReference, $minReadingDuration))
            ->first();
    }

    /**
     * The passage the structure detector identified as the sermon's text, when
     * it emitted one — the strongest possible pairing evidence, since it names
     * the preached reading directly.
     */
    private function sermonReference(ServiceSection $sermonSection): ?string
    {
        $reference = $sermonSection->metadata['sermon_reference'] ?? null;

        return is_string($reference) && trim($reference) !== '' ? $reference : null;
    }

    /**
     * Evidence tuple for ranking a bible reading as the preached text, compared lexicographically
     * by sortByDesc so earlier elements dominate. Every element is "higher is better":
     * 1. its reference overlaps the sermon's own detected reference (the 2023-05-07 corpus run
     *    concatenated an early Psalm into the published audio because the true preached text,
     *    an adjacent 72-second epistle reading, lost every lower tier);
     * 2. linked to a `bibles`-type OoS item (the day's scripture is marked in the order of service);
     * 3. valid timing — ends before the sermon starts (an overlapping reading is worse);
     * 4. a substantive duration over a short preamble (F17, a demotion not a hard exclusion);
     * 5. proximity to the sermon; then longer duration and id as deterministic tie-breaks.
     *
     * @return array{int, int, int, int, float, float, int}
     */
    private function readingEvidence(
        ServiceSection $reading,
        float $sermonStart,
        ?string $sermonReference,
        float $minReadingDuration,
    ): array {
        $gap = $sermonStart - (float) $reading->end_time;
        $item = $reading->churchServiceItem;
        $biblesLinked = $item instanceof ChurchServiceItem && $item->type === 'bibles';

        $readingReference = $reading->metadata['reading_reference'] ?? null;
        $matchesSermonReference = $sermonReference !== null
            && is_string($readingReference)
            && $this->scriptureReferences->referencesOverlap($readingReference, $sermonReference);

        return [
            $matchesSermonReference ? 1 : 0,
            $biblesLinked ? 1 : 0,
            $gap >= 0.0 ? 1 : 0,
            (float) $reading->duration >= $minReadingDuration ? 1 : 0,
            -abs($gap),
            (float) $reading->duration,
            (int) $reading->id,
        ];
    }
}
