<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Exceptions\SegmentationException;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Sermon\SermonExtractionPlanResolver;

/**
 * Deterministic boundary refinement: LLM-proposed section times are proposals;
 * the nearest genuine silence in the RMS log is where a section actually turns
 * over. Boundaries with no silence in range stay put (noted), and a snap never
 * crosses a neighbouring section's midpoint, so ordering is preserved.
 */
class SilenceSnapService
{
    private const BOUNDARY_ROUNDING_OVERLAP_SECONDS = 2.0;

    /**
     * Section types a preacher may hand off to mid-sermon without the sermon
     * having ended. Deliberately narrow: a song or a children's talk between
     * two sermon sections means two separate talks, which must keep failing.
     *
     * @var list<value-of<ServiceSectionType>>
     */
    private const SERMON_INTERRUPTION_TYPES = ['bible_reading', 'prayer'];

    public function __construct(
        private readonly RmsAnalysisService $rmsAnalysisService,
    ) {}

    /**
     * Snap every section boundary to the nearest in-range silence.
     *
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     */
    public function snap(ServiceStructure $structure, string $rmsLogContent): ServiceStructure
    {
        if ($structure->isEmpty()) {
            return $structure;
        }

        $silences = $this->silenceTimes($rmsLogContent);

        if ($silences === []) {
            return $this->withSections(
                $structure,
                $this->mergeInterruptedSermon(
                    $this->reconcileBoundaryRoundingOverlaps($structure, $structure->sections),
                ),
            );
        }

        $window = (float) config('media-processing.service_structure.snap_window_seconds', 30);
        $sections = $structure->sections;
        $snapped = [];

        foreach ($sections as $index => $section) {
            $previous = $sections[$index - 1] ?? null;
            $next = $sections[$index + 1] ?? null;

            // A boundary may move freely between the midpoints of the sections
            // it separates — never across a neighbour's midpoint.
            $startLowerBound = $previous instanceof ServiceStructureSection ? $this->midpoint($previous) : null;
            $midpoint = $this->midpoint($section);
            $endUpperBound = $next instanceof ServiceStructureSection ? $this->midpoint($next) : null;

            $newStart = $this->nearestSilence($silences, $section->startTime, $window, $startLowerBound, $midpoint);
            $newEnd = $this->nearestSilence($silences, $section->endTime, $window, $midpoint, $endUpperBound);

            $notes = [];

            if ($newStart === null) {
                $newStart = $section->startTime;
                $notes[] = sprintf('No silence within %.0fs of the proposed start (%.1fs); left unsnapped.', $window, $section->startTime);
            } elseif (abs($newStart - $section->startTime) > 0.001) {
                $notes[] = sprintf('Start snapped %+.1fs to silence at %.1fs.', $newStart - $section->startTime, $newStart);
            }

            if ($newEnd === null) {
                $newEnd = $section->endTime;
                $notes[] = sprintf('No silence within %.0fs of the proposed end (%.1fs); left unsnapped.', $window, $section->endTime);
            } elseif (abs($newEnd - $section->endTime) > 0.001) {
                $notes[] = sprintf('End snapped %+.1fs to silence at %.1fs.', $newEnd - $section->endTime, $newEnd);
            }

            $snapped[] = $section
                ->withTimes($newStart, $newEnd, $notes)
                ->withSnapDeltas($newStart - $section->startTime, $newEnd - $section->endTime);
        }

        return $this->withSections(
            $structure,
            $this->mergeInterruptedSermon(
                $this->reconcileBoundaryRoundingOverlaps($structure, $snapped),
            ),
        );
    }

    /**
     * Rebuild one sermon from fragments a mid-sermon reading or prayer split apart.
     *
     * A preacher pausing to have someone read, then resuming, is one sermon, not
     * two — the 2024-07-28 corpus run has the request on the transcript ("we've got
     * time, Andrew, would you like to read for us? Revelation 5"), a reading, then
     * the same sermon concluding. The detector reads that correctly; it was the
     * validator's "at most one sermon" rule that had no way to express it.
     *
     * The merged section absorbs the interruption rather than nesting it, so the
     * sermon stays a single contiguous span and every downstream consumer —
     * {@see SermonExtractionPlanResolver} above all, which
     * takes the *first* matching section and would otherwise publish a sermon
     * missing its conclusion — needs no change.
     *
     * Deliberately narrow. It exists only so a structure the detector typed as two
     * sermons can be valid; it does not decide what audio gets published. Which
     * trailing sections belong to the published sermon is
     * {@see SermonExtractionPlanResolver}'s question, and it
     * answers that without destroying section data. So the group grows only from a
     * sermon, through an interruption, into another *sermon* — a song between two
     * sermons still ends it and keeps failing validation as two separate talks —
     * and a merge exceeding the configured sermon ceiling is refused. The result is
     * flagged for manual review, because the merge moves the sermon's own boundaries.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function mergeInterruptedSermon(array $sections): array
    {
        $first = null;

        foreach ($sections as $index => $section) {
            if ($section->type === ServiceSectionType::Sermon) {
                $first = $index;

                break;
            }
        }

        if ($first === null) {
            return $sections;
        }

        // Walk forward while the service is still alternating between the sermon
        // and something it was interrupted by. A continuation only counts once an
        // interruption has been seen, and anything else — a song above all — ends
        // the group.
        $last = null;
        $interrupted = false;

        for ($index = $first + 1; $index < count($sections); $index++) {
            $type = $sections[$index]->type->value;

            if (in_array($type, self::SERMON_INTERRUPTION_TYPES, true)) {
                $interrupted = true;

                continue;
            }

            if ($interrupted && $type === ServiceSectionType::Sermon->value) {
                $last = $index;

                continue;
            }

            break;
        }

        if ($last === null) {
            return $sections;
        }

        $start = $sections[$first]->startTime;
        $end = $sections[$last]->endTime;
        $maxDuration = (float) config(
            'media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds',
            2700,
        );

        if ($maxDuration > 0.0 && ($end - $start) > $maxDuration) {
            return $sections;
        }

        $absorbed = [];

        for ($index = $first + 1; $index <= $last; $index++) {
            $absorbed[] = $sections[$index]->type->value;
        }

        $merged = $sections[$first]
            ->withTimes($start, $end, [sprintf(
                'Sermon interrupted by %s and resumed; merged %d following sections into one sermon (%.1fs-%.1fs).',
                implode(', ', array_unique(array_intersect($absorbed, self::SERMON_INTERRUPTION_TYPES))),
                count($absorbed),
                $start,
                $end,
            )])
            ->withReviewFlags([ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED]);

        return [
            ...array_slice($sections, 0, $first),
            $merged,
            ...array_slice($sections, $last + 1),
        ];
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function reconcileBoundaryRoundingOverlaps(ServiceStructure $original, array $sections): array
    {
        foreach (array_keys($sections) as $index) {
            if ($index === 0) {
                continue;
            }

            $previous = $sections[$index - 1];
            $current = $sections[$index];
            $overlap = $previous->endTime - $current->startTime;

            if ($overlap <= 0.0 || $overlap > self::BOUNDARY_ROUNDING_OVERLAP_SECONDS) {
                continue;
            }

            $boundary = ($previous->endTime + $current->startTime) / 2.0;
            $note = sprintf('Reconciled %.1fs boundary-rounding overlap at %.1fs.', $overlap, $boundary);
            $originalPrevious = $original->sections[$index - 1];
            $originalCurrent = $original->sections[$index];

            $sections[$index - 1] = $previous
                ->withTimes($previous->startTime, $boundary, [$note])
                ->withSnapDeltas(
                    $previous->startTime - $originalPrevious->startTime,
                    $boundary - $originalPrevious->endTime,
                );
            $sections[$index] = $current
                ->withTimes($boundary, $current->endTime, [$note])
                ->withSnapDeltas(
                    $boundary - $originalCurrent->startTime,
                    $current->endTime - $originalCurrent->endTime,
                );
        }

        return array_values($sections);
    }

    /** @param list<ServiceStructureSection> $sections */
    private function withSections(ServiceStructure $structure, array $sections): ServiceStructure
    {
        return ServiceStructure::fromSections(
            $sections,
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );
    }

    /**
     * Sample times whose RMS level sits at or below the silence threshold.
     *
     * Uses the same per-recording calibrated threshold as the segmentation
     * pipeline (adaptive by default), so the snapper sees the same silences
     * the heuristic path does; falls back to the fixed threshold when the
     * adaptive calculation cannot run.
     *
     * @return list<float>
     */
    private function silenceTimes(string $rmsLogContent): array
    {
        try {
            $threshold = (float) $this->rmsAnalysisService->determineThreshold($rmsLogContent)['threshold'];
        } catch (SegmentationException) {
            $threshold = $this->rmsAnalysisService->getRmsThreshold();
        }

        $times = [];

        foreach ($this->rmsAnalysisService->extractRmsData($rmsLogContent) as $sample) {
            if ($sample['rms'] <= $threshold) {
                $times[] = $sample['time'];
            }
        }

        return $times;
    }

    /**
     * The nearest silence to $target within $window seconds, constrained to lie
     * strictly between the exclusive bounds. Null when nothing qualifies.
     *
     * @param  list<float>  $silences
     */
    private function nearestSilence(
        array $silences,
        float $target,
        float $window,
        ?float $lowerBoundExclusive,
        ?float $upperBoundExclusive,
    ): ?float {
        $best = null;
        $bestDistance = INF;

        foreach ($silences as $time) {
            $distance = abs($time - $target);

            if ($distance > $window) {
                continue;
            }

            if ($lowerBoundExclusive !== null && $time <= $lowerBoundExclusive) {
                continue;
            }

            if ($upperBoundExclusive !== null && $time >= $upperBoundExclusive) {
                continue;
            }

            if ($distance < $bestDistance) {
                $best = $time;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function midpoint(ServiceStructureSection $section): float
    {
        return ($section->startTime + $section->endTime) / 2.0;
    }
}
