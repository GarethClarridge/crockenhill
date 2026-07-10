<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
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
     * Applied by DetectServiceStructure when a validated structure has a
     * sermon but no bible_reading section near it, and a feedback-guided
     * retry could not recover one — the reading is likely embedded in
     * another section, so the published sermon audio would lack it.
     */
    public const FLAG_MISSING_PREACHED_READING = 'structure_missing_preached_reading';

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

        $this->checkChronology($structure, $hardFailures);
        $this->checkRecordingBounds($structure, $context, $hardFailures);
        $this->checkCoverage($structure, $context, $hardFailures);
        $this->checkSermons($structure, $hardFailures);
        $crossTypeInversions = $this->checkOosAnchoring($structure, $context, $hardFailures);

        $annotated = $this->annotateSoftFlags($structure, $context, $crossTypeInversions);

        return new ValidationResult(
            structure: $annotated,
            hardFailures: $hardFailures,
            unmatchedOosItemIds: $this->unmatchedOosItemIds($structure, $context),
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
     * @return array<int, true> Indices of sections whose claimed item precedes
     *                          an earlier-claimed item of a different type.
     */
    private function checkOosAnchoring(ServiceStructure $structure, ValidationContext $context, array &$hardFailures): array
    {
        $claimed = [];

        /** @var array<string, array{itemId: int, position: int}> $lastClaimedByType */
        $lastClaimedByType = [];
        $highestClaimedPosition = null;
        $crossTypeInversions = [];

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
            // "Same type" means the RAW OpenLP type: only raw types (songs,
            // bibles) are authored as printed blocks. The semantic mapping
            // collapses distinct raw types into one bucket (a `custom`
            // "Reading" slide and a `presentations` children's-talk file both
            // become `other`), and items that were never a printed block must
            // not be chained by it (the 2024-05-05 corpus false alarm).
            $orderingType = $context->oosItemRawTypes[$itemId] ?? $itemType->value;
            $position = $context->oosItemPositions[$itemId] ?? null;

            if ($position !== null) {
                $lastOfType = $lastClaimedByType[$orderingType] ?? null;

                if ($lastOfType !== null && $position < $lastOfType['position']) {
                    $hardFailures[] = [
                        'code' => 'out_of_order_oos_items',
                        'message' => sprintf(
                            'Section %d claims OoS item %d (position %d) after item %d (position %d); claimed items of the same type must follow the planned order.',
                            $index + 1,
                            $itemId,
                            $position,
                            $lastOfType['itemId'],
                            $lastOfType['position']
                        ),
                    ];
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

        return $crossTypeInversions;
    }

    /**
     * @param  array<int, true>  $crossTypeInversions
     */
    private function annotateSoftFlags(ServiceStructure $structure, ValidationContext $context, array $crossTypeInversions): ServiceStructure
    {
        $minSectionSeconds = (float) config('media-processing.service_structure.min_section_seconds', 15);
        $benedictionMaxDuration = (float) config('media-processing.reading_references.benediction_max_duration_seconds', 60);

        $sections = [];

        foreach ($structure->sections as $index => $section) {
            $flags = [];

            if ($section->confidence < ServiceSectionConfidence::HIGH_THRESHOLD) {
                $flags[] = self::FLAG_LOW_CONFIDENCE;
            }

            if ($section->duration() < $minSectionSeconds) {
                $flags[] = self::FLAG_MICRO_SECTION;
            }

            if ($section->type === ServiceSectionType::BibleReading
                && $section->duration() <= $benedictionMaxDuration
                && $section->endTime >= $context->recordingDuration - self::BENEDICTION_END_WINDOW_SECONDS) {
                $flags[] = self::FLAG_BENEDICTION_SUSPECT;
            }

            if (isset($crossTypeInversions[$index])) {
                $flags[] = self::FLAG_OOS_CROSS_TYPE_INVERSION;
            }

            $sections[] = $flags === [] ? $section : $section->withReviewFlags($flags);
        }

        return new ServiceStructure($sections, $structure->notes, $structure->model);
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
