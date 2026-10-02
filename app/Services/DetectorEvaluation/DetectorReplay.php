<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorEntry;
use App\Data\DetectorSignal;
use App\Enums\DetectorStatus;
use App\Models\MediaProcessingLog;
use App\Support\DetectorCatalogue;

/**
 * Read every promoted detector's recorded output over a population and
 * reconcile it against the holds that output was supposed to raise.
 *
 * §4.3a's third detector checkbox asks for exactly this, and H7 names it
 * `detectors:replay`. It is the step that turns the catalogue and the five
 * adapters from an inventory into a measurement.
 *
 * **Read-only, permanently.** There is no apply path and there never should be.
 * Reconciliation names discrepancies; deciding what to do about one is an
 * operator act through the pipeline, and a report that quietly corrected what it
 * measured would destroy the thing it was measuring.
 *
 * **The denominator is computed, never quoted.** The plan says 437 eligible runs
 * "at this review" and warns that older 442-run results are dated baselines
 * rather than current denominators; the eligible set today is different again.
 * So this counts the population it actually read and reports it, and every rate
 * a reader derives is against that number.
 *
 * **Weekly runs are reported separately and never merged**, because they are a
 * different recording regime under different code, and averaging them into the
 * historic corpus would hide both.
 *
 * **Absence is not agreement.** Three of the five surfaces distinguish "assessed
 * and clear" from "never assessed" — the transcript and video surfaces at run
 * level, the two song surfaces per section — and the ones that do not are
 * reported as such. Counting unassessed runs as clean is the single mistake this
 * whole section of the plan keeps having to unlearn: it fills the clean column
 * with exactly the runs most likely to be defective.
 */
class DetectorReplay
{
    public function __construct(
        private readonly SectionReviewFlagSignals $sectionFlags,
        private readonly SuspectTranscriptBlockSignals $transcriptBlocks,
        private readonly VideoQualityVerdictSignals $videoVerdicts,
        private readonly SongPublicationReviewSignals $songReview,
        private readonly SongBoundaryEvidenceSignals $songBoundary,
    ) {}

    /**
     * @param  iterable<MediaProcessingLog>  $runs
     * @return array<string, mixed>
     */
    public function over(iterable $runs): array
    {
        $perDetector = [];
        $uncatalogued = [];
        $nonDetector = [];
        $runIds = [];
        $coverage = [
            'transcript_screened' => 0,
            'transcript_unscreened' => 0,
            'video_assessed' => 0,
            'video_unassessed' => 0,
        ];

        foreach ($runs as $run) {
            $runIds[] = (int) $run->id;

            foreach ($this->signalsFor($run) as $signal) {
                $this->record($perDetector, $uncatalogued, $nonDetector, $signal);
            }

            $this->recordCoverage($coverage, $run);
        }

        return [
            'runs_read' => count($runIds),
            'run_ids' => $runIds,
            'coverage' => $coverage,
            'detectors' => $this->withSilentDetectors($perDetector),
            'uncatalogued_signals' => $this->sorted($uncatalogued),
            'non_detector_flags' => $this->sorted($nonDetector),
        ];
    }

    /**
     * Every stored signal this run carries, across all five surfaces.
     *
     * A surface returning null means the run was never assessed on it, which is
     * recorded in the coverage counters rather than read as no findings.
     *
     * @return list<DetectorSignal>
     */
    public function signalsFor(MediaProcessingLog $run): array
    {
        return [
            ...$this->sectionFlags->for($run),
            ...($this->transcriptBlocks->for($run) ?? []),
            ...($this->videoVerdicts->for($run) ?? []),
            ...$this->songReview->for($run),
            ...$this->songBoundary->for($run),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $perDetector
     * @param  array<string, int>  $uncatalogued
     * @param  array<string, int>  $nonDetector
     */
    private function record(
        array &$perDetector,
        array &$uncatalogued,
        array &$nonDetector,
        DetectorSignal $signal,
    ): void {
        if ($signal->detectorId === null) {
            $key = $signal->surface->value.'::'.$signal->signal;

            // Known not to be a detector finding — an operator's containment, or
            // a hold restating a finding another surface already reported. Kept
            // in its own bucket so it neither reads as a gap nor inflates a
            // detector's coverage.
            if (DetectorCatalogue::isNonDetectorFlag($signal->signal)) {
                $nonDetector[$key] = ($nonDetector[$key] ?? 0) + 1;

                return;
            }

            // Carried through, never dropped: a stored signal no entry claims is
            // either a flag whose raise site was deleted without retiring it, or
            // a detector that shipped without an evaluation entry. Both are the
            // coverage gap §4.3a exists to close.
            $uncatalogued[$key] = ($uncatalogued[$key] ?? 0) + 1;

            return;
        }

        $row = $perDetector[$signal->detectorId] ?? [
            'signals' => 0,
            'held' => 0,
            'unheld' => 0,
            'runs' => [],
            'sections' => [],
        ];

        $row['signals']++;
        $row[$signal->held ? 'held' : 'unheld']++;
        $row['runs'][$signal->runId] = true;

        if ($signal->sectionId !== null) {
            $row['sections'][$signal->sectionId] = true;
        }

        $perDetector[$signal->detectorId] = $row;
    }

    /**
     * @param  array<string, int>  $coverage
     */
    private function recordCoverage(array &$coverage, MediaProcessingLog $run): void
    {
        $coverage[$this->transcriptBlocks->wasScreened($run) ? 'transcript_screened' : 'transcript_unscreened']++;
        $coverage[$this->videoVerdicts->wasAssessed($run) ? 'video_assessed' : 'video_unassessed']++;
    }

    /**
     * Fold in the promoted detectors that produced nothing at all.
     *
     * A detector silent across the whole corpus is the most interesting row in
     * the report and the easiest to lose, because a table built only from
     * findings cannot contain it. Silence is either a class that genuinely does
     * not occur or a detector that has stopped working, and nothing in the
     * output distinguishes those — which is precisely why it has to be named
     * rather than omitted.
     *
     * @param  array<string, array<string, mixed>>  $perDetector
     * @return array<string, array<string, mixed>>
     */
    private function withSilentDetectors(array $perDetector): array
    {
        $rows = [];

        foreach (DetectorCatalogue::all() as $entry) {
            if ($entry->status !== DetectorStatus::Promoted) {
                continue;
            }

            $found = $perDetector[$entry->id] ?? null;

            $rows[$entry->id] = [
                ...$this->describe($entry),
                'signals' => $found['signals'] ?? 0,
                'held' => $found['held'] ?? 0,
                'unheld' => $found['unheld'] ?? 0,
                'runs' => count($found['runs'] ?? []),
                'sections' => count($found['sections'] ?? []),
                'silent' => $found === null,
            ];
        }

        uasort($rows, static fn (array $a, array $b): int => $b['signals'] <=> $a['signals']);

        return $rows;
    }

    /**
     * @return array<string, string|null>
     */
    private function describe(DetectorEntry $entry): array
    {
        return [
            'surface' => $entry->surface?->value,
            'severity' => $entry->severity->value,
            'unit' => $entry->unit->value,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        arsort($counts);

        return $counts;
    }
}
