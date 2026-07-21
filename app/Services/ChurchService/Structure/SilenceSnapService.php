<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Exceptions\SegmentationException;
use App\Services\Media\Audio\RmsAnalysisService;

/**
 * Deterministic boundary refinement: LLM-proposed section times are proposals;
 * the nearest genuine silence in the RMS log is where a section actually turns
 * over. Boundaries with no silence in range stay put (noted), and a snap never
 * crosses a neighbouring section's midpoint, so ordering is preserved.
 */
class SilenceSnapService
{
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
            return $structure;
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

        return ServiceStructure::fromSections(
            $snapped,
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
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
