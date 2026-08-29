<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Support\SectionReviewFlagPolicy;
use App\Support\ServiceSectionConfidence;

/**
 * The deterministic gate between LLM structure proposals and persistence.
 *
 * Hard failures route the run to manual review; soft failures annotate
 * individual sections with review flags and let the run continue. No LLM
 * output reaches ServiceSectionSyncService::sync() without passing this.
 */
class ServiceStructureValidator
{
    public const FLAG_LOW_CONFIDENCE = 'structure_low_confidence';

    public const FLAG_MICRO_SECTION = 'structure_micro_section';

    public const FLAG_BENEDICTION_SUSPECT = 'structure_benediction_suspect';

    public const FLAG_OOS_CROSS_TYPE_INVERSION = 'structure_oos_cross_type_inversion';

    /**
     * A section claims an OoS item printed *before* one an earlier section
     * already claimed, within the same OpenLP item type.
     *
     * This was a hard failure on the premise that only a same-type inversion
     * could mean the detector had swapped two sections. The 2023-08-20 corpus
     * run disproved it: the transcript has the service leader announcing the
     * deviation out loud ("It's not in the book", "Not that song — we're going
     * to sing that"), and the detector's claims matched the sung lyrics. Songs
     * really are performed out of printed order here — 2 of 35 services with
     * two or more persisted song claims (6%) already show it, against 42% for
     * the cross-type inversion that has always been tolerated.
     *
     * So it is a flag, not a blocker. Unlike {@see self::FLAG_OOS_CROSS_TYPE_INVERSION}
     * it is NOT demoted in {@see SectionReviewFlagPolicy}: at 6% it is
     * both rare enough to review and the stronger of the two signals. It does not
     * block sermon auto-extraction — an ordering flag says nothing about boundaries.
     */
    public const FLAG_OOS_SAME_TYPE_INVERSION = 'structure_oos_same_type_inversion';

    /**
     * One sermon that {@see SilenceSnapService}
     * rebuilt from sermon fragments separated only by a reading or a prayer.
     *
     * Deliberately forces manual review and blocks auto-extraction: the merge
     * moves the sermon's own boundaries, and the pattern is rare enough that a
     * human should confirm it rather than have a mis-merge publish silently.
     */
    public const FLAG_SERMON_INTERRUPTION_MERGED = 'structure_sermon_interruption_merged';

    public const FLAG_OOS_STRUCTURE_MISMATCH = 'oos_structure_mismatch';

    /**
     * Applied by DetectServiceStructure when a validated structure has a
     * sermon but no bible_reading section near it, and a feedback-guided
     * retry could not recover one — the reading is likely embedded in
     * another section, so the published sermon audio would lack it.
     */
    public const FLAG_MISSING_PREACHED_READING = 'structure_missing_preached_reading';

    /**
     * The detector names a song twice for the same window — once as the section's
     * `songTitle`, once as the chapter marker covering it — and nothing downstream
     * reconciles them. When they name different songs, one of them is wrong, and
     * the section's confidence says nothing about which: both observed cases scored
     * 0.98 and 1.000 with `needs_manual_review` false.
     *
     * The consequence is not cosmetic. The section's `songTitle` drives the
     * catalogue link, {@see ChurchServiceSongLinker}
     * writes that song's title onto the item, and the marker's naming survives only
     * inside `source_evidence`, where nothing reads it. A wrong link also breaks the
     * merge into the planned OoS item, which mints a duplicate item instead.
     *
     * This flag records the disagreement only. It deliberately does not pick a
     * winner: the marker was right in both observed cases, but two cases are not a
     * rule, and guessing is what produced the defect.
     */
    public const FLAG_SONG_TITLE_MARKER_MISMATCH = 'song_title_marker_mismatch';

    /**
     * All review flags that an alignment/structure pass owns and recalculates:
     * cleared at the start of each run and only re-added when still applicable.
     * These flags are cleared before each validation pass so re-runs are
     * idempotent.
     *
     * @var array<int, string>
     */
    public const OOS_REVIEW_FLAGS = [
        self::FLAG_OOS_STRUCTURE_MISMATCH,
        'unmatched_song_section',
        'song_alignment_inferred',
        'song_name_reference_only',
        'ambiguous_childrens_talk',
        'inferred_childrens_talk',
        'presentation_positional_fallback',
        self::FLAG_LOW_CONFIDENCE,
        self::FLAG_MICRO_SECTION,
        self::FLAG_BENEDICTION_SUSPECT,
        self::FLAG_SONG_TITLE_MARKER_MISMATCH,
        'unknown_section_type',
    ];

    /**
     * All review reasons that an alignment/structure pass owns and recalculates.
     *
     * @var array<int, string>
     */
    public const OOS_REVIEW_REASONS = [
        self::FLAG_OOS_STRUCTURE_MISMATCH,
        'unmatched_song_section',
        'song_alignment_inferred',
        'song_name_reference_only',
        'ambiguous_childrens_talk',
        'inferred_childrens_talk',
        'presentation_positional_fallback',
        self::FLAG_LOW_CONFIDENCE,
        self::FLAG_MICRO_SECTION,
        self::FLAG_BENEDICTION_SUSPECT,
        'unknown_section_type',
    ];

    /**
     * Seconds of slack allowed at the end of the recording (transcription
     * duration and section ends can disagree by a rounding margin).
     */
    private const END_TOLERANCE_SECONDS = 1.0;

    /**
     * Overlaps below this are treated as boundary rounding, not real overlap.
     */
    private const OVERLAP_TOLERANCE_SECONDS = 0.05;

    /**
     * A short bible_reading ending this close to the end of the recording is
     * suspected of being a closing benediction, not the preached text (F12).
     */
    private const BENEDICTION_END_WINDOW_SECONDS = 120.0;

    public function validate(ServiceStructure $structure, ValidationContext $context): ValidationResult
    {
        $hardFailures = [];

        if ($structure->isEmpty()) {
            return new ValidationResult($structure, [
                ['code' => 'no_sections', 'message' => 'The detected structure contains no sections.'],
            ]);
        }

        $structure = $this->dropSongsTheRecordingCannotContain($structure, $context);

        $this->checkChronology($structure, $hardFailures);
        $this->checkRecordingBounds($structure, $context, $hardFailures);
        $this->checkCoverage($structure, $context, $hardFailures);
        $this->checkSermons($structure, $hardFailures);
        $inversions = $this->checkOosAnchoring($structure, $context, $hardFailures);

        $annotated = $this->annotateSoftFlags($structure, $context, $inversions);

        return new ValidationResult(
            structure: $annotated,
            hardFailures: $hardFailures,
            unmatchedOosItemIds: $this->unmatchedOosItemIds($structure, $context),
        );
    }

    /**
     * Does the chapter marker covering this song section name a different song than
     * the section itself?
     *
     * Only songs are checked: a marker over a prayer or a reading is a description,
     * not a second naming of the same thing. Comparison is on normalised text with
     * containment allowed, so "All Praise to Him" against "All Praise To Him" and
     * "Come And See" against "Come And See #415" are agreement, not disagreement —
     * hymnbook numbers and casing are the common benign difference.
     *
     * @param  list<array{title: string, start_time: float, end_time: float}>|array<int|string, mixed>  $chapterMarkers
     */
    private function songTitleContradictsChapterMarker(ServiceStructureSection $section, array $chapterMarkers): bool
    {
        if ($section->type !== ServiceSectionType::Song) {
            return false;
        }

        $sectionTitle = self::normaliseSongTitle((string) $section->songTitle);

        if ($sectionTitle === '') {
            return false;
        }

        $markerTitle = self::normaliseSongTitle(
            $this->coveringChapterMarkerTitle($section, $chapterMarkers) ?? ''
        );

        if ($markerTitle === '') {
            return false;
        }

        return ! str_contains($sectionTitle, $markerTitle) && ! str_contains($markerTitle, $sectionTitle);
    }

    /**
     * The title of the marker overlapping this section by the most time, or null
     * when no marker covers it.
     *
     * Boundaries are compared with overlap rather than equality: snapping moves a
     * section's edges to silence after the markers are written, so the two rarely
     * share an exact boundary.
     *
     * @param  list<array{title: string, start_time: float, end_time: float}>|array<int|string, mixed>  $chapterMarkers
     */
    private function coveringChapterMarkerTitle(ServiceStructureSection $section, array $chapterMarkers): ?string
    {
        $bestTitle = null;
        $bestOverlap = 0.0;

        foreach ($chapterMarkers as $marker) {
            if (! is_array($marker)) {
                continue;
            }

            $title = $marker['title'] ?? null;

            if (! is_string($title)) {
                continue;
            }

            $overlap = min($section->endTime, (float) ($marker['end_time'] ?? 0))
                - max($section->startTime, (float) ($marker['start_time'] ?? 0));

            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $bestTitle = $title;
            }
        }

        return $bestTitle;
    }

    private static function normaliseSongTitle(string $title): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title)));
    }

    /**
     * Reclassify song sections on a recording that contains no songs.
     *
     * A concatenated historic recording is the fragments that survive between the
     * songs; the songs themselves were excised for copyright before the recording
     * was ever assembled. Asking a detector to find them is asking for a wrong
     * answer, and it produced two: a candidate arm labelled four song sections
     * across the unhearable first nineteen minutes of the 2024-12-22 carol service
     * and then anchored them to carol items out of printed order, failing hard on
     * `out_of_order_oos_items` for a sequence nothing in the audio could establish.
     *
     * The section is not deleted — something was said in that window and coverage
     * still has to account for it — but it cannot be a song, so it loses the type,
     * the song title and any claim on an order-of-service item.
     */
    private function dropSongsTheRecordingCannotContain(ServiceStructure $structure, ValidationContext $context): ServiceStructure
    {
        if (! $context->recordingOmitsSongs) {
            return $structure;
        }

        $sections = [];
        $changed = false;

        foreach ($structure->sections as $section) {
            if ($section->type !== ServiceSectionType::Song) {
                $sections[] = $section;

                continue;
            }

            $changed = true;
            $sections[] = $section->asNonSongInSongLessRecording();
        }

        if (! $changed) {
            return $structure;
        }

        return new ServiceStructure(
            $sections,
            [...$structure->notes, 'Song sections were reclassified: this recording is concatenated, so it contains no songs.'],
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
        );
    }

    /**
     * @param  list<array{code: string, message: string}>  $hardFailures
     */
    private function checkChronology(ServiceStructure $structure, array &$hardFailures): void
    {
        $previous = null;

        foreach ($structure->sections as $index => $section) {
            if ($section->duration() <= 0.0) {
                $hardFailures[] = [
                    'code' => 'non_chronological',
                    'message' => sprintf(
                        'Section %d (%s) has a non-positive duration (%.1fs–%.1fs).',
                        $index + 1,
                        $section->type->value,
                        $section->startTime,
                        $section->endTime
                    ),
                ];

                return;
            }

            if ($previous instanceof ServiceStructureSection
                && $section->startTime < $previous->endTime - self::OVERLAP_TOLERANCE_SECONDS) {
                $hardFailures[] = [
                    'code' => 'non_chronological',
                    'message' => sprintf(
                        'Section %d (%s, starts %.1fs) overlaps the previous section (%s, ends %.1fs).',
                        $index + 1,
                        $section->type->value,
                        $section->startTime,
                        $previous->type->value,
                        $previous->endTime
                    ),
                ];

                return;
            }

            $previous = $section;
        }
    }

    /**
     * @param  list<array{code: string, message: string}>  $hardFailures
     */
    private function checkRecordingBounds(ServiceStructure $structure, ValidationContext $context, array &$hardFailures): void
    {
        foreach ($structure->sections as $index => $section) {
            if ($section->endTime > $context->recordingDuration + self::END_TOLERANCE_SECONDS) {
                $hardFailures[] = [
                    'code' => 'timestamps_outside_recording',
                    'message' => sprintf(
                        'Section %d (%s) ends at %.1fs, beyond the %.1fs recording.',
                        $index + 1,
                        $section->type->value,
                        $section->endTime,
                        $context->recordingDuration
                    ),
                ];

                return;
            }
        }
    }

    /**
     * @param  list<array{code: string, message: string}>  $hardFailures
     */
    private function checkCoverage(ServiceStructure $structure, ValidationContext $context, array &$hardFailures): void
    {
        if ($context->speechDuration <= 0.0) {
            return;
        }

        $floor = (float) config('media-processing.service_structure.coverage_floor', 0.7);
        $coverage = $this->coveredSpeechSeconds($structure, $context) / $context->speechDuration;

        if ($coverage < $floor) {
            $hardFailures[] = [
                'code' => 'insufficient_coverage',
                'message' => sprintf(
                    'Sections cover %.0f%% of the recording\'s %.0fs of speech; the floor is %.0f%%.',
                    $coverage * 100,
                    $context->speechDuration,
                    $floor * 100
                ),
            ];
        }
    }

    /**
     * The speech time the sections actually cover: the sum of each transcript
     * cue's overlap with the proposed sections. Raw section duration would let
     * a long section in the wrong (silent) part of the recording satisfy the
     * floor despite covering none of what was said. Contexts built without
     * cues fall back to summing section durations.
     */
    private function coveredSpeechSeconds(ServiceStructure $structure, ValidationContext $context): float
    {
        if ($context->cues === []) {
            return array_sum(array_map(
                static fn (ServiceStructureSection $section): float => $section->duration(),
                $structure->sections
            ));
        }

        $covered = 0.0;

        foreach ($context->cues as $cue) {
            $cueCovered = 0.0;

            foreach ($structure->sections as $section) {
                $overlap = min($cue['end'], $section->endTime) - max($cue['start'], $section->startTime);

                if ($overlap > 0.0) {
                    $cueCovered += $overlap;
                }
            }

            // Overlapping sections (a hard failure in their own right) must
            // not let a cue count for more than its own length.
            $covered += min($cueCovered, $cue['end'] - $cue['start']);
        }

        return $covered;
    }

    /**
     * @param  list<array{code: string, message: string}>  $hardFailures
     */
    private function checkSermons(ServiceStructure $structure, array &$hardFailures): void
    {
        $sermons = $structure->sectionsOfType(ServiceSectionType::Sermon);

        if (count($sermons) > 1) {
            $hardFailures[] = [
                'code' => 'multiple_sermons',
                'message' => sprintf('The structure contains %d sermon sections; a service has at most one.', count($sermons)),
            ];

            return;
        }

        if ($sermons === []) {
            return;
        }

        $sermon = $sermons[0];
        $minDuration = (float) config('media-processing.segmentation.min_sermon_duration', 300.0);
        $maxDuration = (float) config(
            'media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds',
            2700
        );

        if ($sermon->duration() < $minDuration || ($maxDuration > 0.0 && $sermon->duration() > $maxDuration)) {
            $hardFailures[] = [
                'code' => 'sermon_duration_out_of_bounds',
                'message' => sprintf(
                    'The sermon lasts %.0fs; plausible sermons run %.0f–%.0fs.',
                    $sermon->duration(),
                    $minDuration,
                    $maxDuration
                ),
            ];
        }
    }

    /**
     * @param  list<array{code: string, message: string}>  $hardFailures
     * @return array{cross_type: array<int, true>, same_type: array<int, true>} Section
     *                                                                          indices whose claimed item precedes an earlier-claimed
     *                                                                          item, split by whether the two share an OpenLP type.
     */
    private function checkOosAnchoring(ServiceStructure $structure, ValidationContext $context, array &$hardFailures): array
    {
        $claimed = [];

        /** @var array<string, array{itemId: int, position: int}> $lastClaimedByType */
        $lastClaimedByType = [];
        $highestClaimedPosition = null;
        $crossTypeInversions = [];
        $sameTypeInversions = [];

        foreach ($structure->sections as $index => $section) {
            $itemId = $section->oosItemId;

            if ($itemId === null) {
                continue;
            }

            if (! array_key_exists($itemId, $context->oosItemTypes)) {
                $hardFailures[] = [
                    'code' => 'unknown_oos_item',
                    'message' => sprintf('Section %d (%s) claims OoS item %d, which does not exist for this service.', $index + 1, $section->type->value, $itemId),
                ];

                continue;
            }

            if (in_array($itemId, $claimed, true)) {
                $hardFailures[] = [
                    'code' => 'duplicate_oos_item',
                    'message' => sprintf('OoS item %d is claimed by more than one section.', $itemId),
                ];

                continue;
            }

            $claimed[] = $itemId;

            $itemType = $context->oosItemTypes[$itemId];

            // OpenLP exports group items by type (all songs in one block), so
            // the printed positions of different types routinely disagree with
            // the performed order. Only a same-type inversion signals a
            // detector swap — persisting each section against the wrong
            // service item — so only that fails hard; a cross-type inversion
            // is a legitimate authoring style and merely earns a review flag.
            //
            // "Same type" means the raw OpenLP type, except `custom`: custom
            // items are heterogeneous prayers, notices, readings and sermons,
            // not one authored block. Chaining them creates false hard failures
            // when those independent items are performed out of printed order.
            $rawType = $context->oosItemRawTypes[$itemId] ?? $itemType->value;
            $orderingType = $rawType === 'custom' ? "custom:{$itemId}" : $rawType;
            $position = $context->oosItemPositions[$itemId] ?? null;

            if ($position !== null) {
                $lastOfType = $lastClaimedByType[$orderingType] ?? null;

                if ($lastOfType !== null && $position < $lastOfType['position']) {
                    // The high-water mark is deliberately not advanced: a later
                    // claim is still measured against the furthest item reached.
                    $sameTypeInversions[$index] = true;
                } else {
                    $lastClaimedByType[$orderingType] = ['itemId' => $itemId, 'position' => $position];

                    if ($highestClaimedPosition !== null && $position < $highestClaimedPosition) {
                        $crossTypeInversions[$index] = true;
                    }

                    $highestClaimedPosition = max($highestClaimedPosition ?? $position, $position);
                }
            }

            // A generic OoS item (semantic type "other", e.g. "Andrew Talk.pptx")
            // may anchor any section type — that ambiguity is exactly what the
            // transcript-grounded detector resolves (F15).
            if ($itemType !== ServiceSectionType::Other && $itemType !== $section->type) {
                $hardFailures[] = [
                    'code' => 'incompatible_oos_item',
                    'message' => sprintf(
                        'Section %d is a %s but claims OoS item %d, which is a %s.',
                        $index + 1,
                        $section->type->value,
                        $itemId,
                        $itemType->value
                    ),
                ];
            }
        }

        return ['cross_type' => $crossTypeInversions, 'same_type' => $sameTypeInversions];
    }

    /**
     * @param  array{cross_type: array<int, true>, same_type: array<int, true>}  $inversions
     */
    private function annotateSoftFlags(ServiceStructure $structure, ValidationContext $context, array $inversions): ServiceStructure
    {
        $minSectionSeconds = (float) config('media-processing.service_structure.min_section_seconds', 15);

        $sections = [];

        foreach ($structure->sections as $index => $section) {
            $flags = [];

            if ($section->confidence < ServiceSectionConfidence::HIGH_THRESHOLD) {
                $flags[] = self::FLAG_LOW_CONFIDENCE;
            }

            if ($section->duration() < $minSectionSeconds) {
                $flags[] = self::FLAG_MICRO_SECTION;
            }

            if (self::isBenedictionSuspect(
                $section->type,
                $section->duration(),
                $section->endTime,
                $context->recordingDuration,
            )) {
                $flags[] = self::FLAG_BENEDICTION_SUSPECT;
            }

            if (isset($inversions['cross_type'][$index])) {
                $flags[] = self::FLAG_OOS_CROSS_TYPE_INVERSION;
            }

            if (isset($inversions['same_type'][$index])) {
                $flags[] = self::FLAG_OOS_SAME_TYPE_INVERSION;
            }

            if ($this->songTitleContradictsChapterMarker($section, $structure->chapterMarkers)) {
                $flags[] = self::FLAG_SONG_TITLE_MARKER_MISMATCH;
            }

            $sections[] = $flags === [] ? $section : $section->withReviewFlags($flags);
        }

        return new ServiceStructure(
            $sections,
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
        );
    }

    /**
     * Whether a section is a suspected closing benediction: a short bible_reading
     * ending within the end-of-recording window (a doxology read verbatim, not
     * the preached text). Shared by structure validation and the backfill command
     * so both agree on the geometry (F12).
     */
    public static function isBenedictionSuspect(
        ServiceSectionType $type,
        float $durationSeconds,
        float $endTime,
        float $recordingDuration,
    ): bool {
        if ($type !== ServiceSectionType::BibleReading) {
            return false;
        }

        $maxDuration = (float) config('media-processing.reading_references.benediction_max_duration_seconds', 60);

        return $durationSeconds <= $maxDuration
            && $endTime >= $recordingDuration - self::BENEDICTION_END_WINDOW_SECONDS;
    }

    /**
     * @return list<int>
     */
    private function unmatchedOosItemIds(ServiceStructure $structure, ValidationContext $context): array
    {
        $claimed = array_values(array_filter(array_map(
            static fn (ServiceStructureSection $section): ?int => $section->oosItemId,
            $structure->sections
        )));

        return array_values(array_filter(
            array_keys($context->oosItemTypes),
            static fn (int $itemId): bool => ! in_array($itemId, $claimed, true)
        ));
    }
}
