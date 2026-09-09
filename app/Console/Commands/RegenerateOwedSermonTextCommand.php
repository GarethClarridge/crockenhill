<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricSermonTranscriptSpanRepair;
use App\Services\HistoricMedia\SermonTranscriptSpanRepairEntry;
use App\Support\SermonTextRegenerationDebt;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-slices the sermon text of runs whose full-service transcript was replaced.
 *
 * P8-Q14's recovery rewrote 150 full-service transcripts, which took the loops
 * out of the evidence but left every *saved sermon text* sliced from the
 * transcript that was replaced — still holding every repetition a reader sees.
 * `FlagSermonTextPredatesEvidence` raised a hold for exactly that, and only the
 * writer that re-slices can withdraw it.
 *
 * **Membership is narrower than "owed".** The plan held all regeneration behind
 * P8-Q15 on the grounds that it settles the spans. Measured, that gate is
 * per-run: of the 149 owed runs only two contain a P8-Q15 section, and 91 carry
 * no span doubt anywhere on the run. This command takes the settled ones and
 * leaves the rest, because re-slicing to spans that are still in question would
 * clear the hold while banking text nobody has agreed is right — the same
 * laundering the hold exists to prevent.
 *
 * The re-slice itself is {@see HistoricSermonTranscriptSpanRepair}, asked
 * without its concatenated-plan gate, which is the caller its own docblock
 * anticipates: "a caller whose service transcript has since changed asks without
 * this gate". That service reads under the run's staging batch and writes in
 * place on the disk the sermon actually points at, neither of which the pipeline
 * job would do when run outside a pipeline.
 *
 * Deletion trigger: delete once no run is owed a settled re-derivation.
 */
class RegenerateOwedSermonTextCommand extends Command
{
    protected $signature = 'historic-import:regenerate-owed-sermon-text
                            {--processing-id=* : Restrict to these exact processing IDs}
                            {--limit= : Regenerate at most this many runs, oldest first}
                            {--include-unsettled : Also take runs whose spans are still in question (not advised)}
                            {--execute : Write the regenerated text (default: dry run)}
                            {--show-skipped : List the runs held back and why}';

    protected $description = 'Re-slice sermon text for runs whose full-service transcript was replaced';

    public function handle(HistoricSermonTranscriptSpanRepair $repair): int
    {
        try {
            $execute = (bool) $this->option('execute');
            $selection = $this->select();

            /**
             * Report before returning, always. A pass that held every owed run
             * back is not an empty pass, and saying "nothing is owed" when 58
             * runs are waiting on P8-Q15 would hide the debt behind a
             * reassuring line.
             */
            $this->report($selection);

            if ($selection['runs'] === []) {
                $this->warn($selection['held'] === []
                    ? 'No run is owed a settled sermon re-derivation.'
                    : 'Every owed run is held back for unsettled spans; none was regenerated.');

                return self::SUCCESS;
            }

            $entries = $repair->inspect($selection['runs'], requireConcatenatedPlan: false);
            $this->reportEntries($entries);

            $repairable = array_values(array_filter(
                $entries,
                static fn (SermonTranscriptSpanRepairEntry $entry): bool => $entry->isRepairable(),
            ));

            if (! $execute) {
                $this->warn('DRY RUN: nothing was written.');
                $this->warn(sprintf('%d sermon text(s) would be regenerated.', count($repairable)));
                $this->line('Re-run with --execute for this exact selection.');

                return self::SUCCESS;
            }

            $totals = $repair->apply($entries);
            $this->info("Regenerated {$totals['repaired']} sermon text(s).");

            if ($totals['reconciled'] > 0) {
                $this->info(sprintf(
                    'Reconciled %d run(s) whose saved text already matched the current derivation.',
                    $totals['reconciled'],
                ));
            }

            foreach ($totals['failures'] as $failure) {
                $this->error($failure);
            }

            /**
             * Counted across every status, not just the completed runs this
             * pass can act on. A run this command declines to touch is still
             * owed, and reporting the smaller number would retire the debt in
             * the report rather than in the data.
             */
            $this->line(sprintf(
                '%d run(s) remain owed a re-derivation.',
                MediaProcessingLog::query()
                    ->get()
                    ->filter(static fn (MediaProcessingLog $run): bool => $run->sermonDerivationIsOwed())
                    ->count(),
            ));

            return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * The runs owed a re-derivation, split by whether their spans are settled.
     *
     * @return array{runs: list<MediaProcessingLog>, held: list<array{0: string, 1: string}>}
     */
    private function select(): array
    {
        $processingIds = array_values(array_filter((array) $this->option('processing-id')));
        $unsettled = SermonTextRegenerationDebt::runsWithUnsettledSpans();
        $includeUnsettled = (bool) $this->option('include-unsettled');

        $query = MediaProcessingLog::query()
            ->where('status', ProcessingStatus::Completed)
            ->orderBy('id');

        if ($processingIds !== []) {
            $query->whereIn('processing_id', $processingIds);
        }

        $runs = [];
        $held = [];

        foreach ($query->get() as $run) {
            if (! $run->sermonDerivationIsOwed()) {
                continue;
            }

            if (! $includeUnsettled && isset($unsettled[$run->id])) {
                $held[] = [(string) $run->processing_id, $unsettled[$run->id]];

                continue;
            }

            $runs[] = $run;
        }

        $limit = $this->option('limit');

        if (is_numeric($limit)) {
            $runs = array_slice($runs, 0, (int) $limit);
        }

        return ['runs' => $runs, 'held' => $held];
    }

    /**
     * @param  array{runs: list<MediaProcessingLog>, held: list<array{0: string, 1: string}>}  $selection
     */
    private function report(array $selection): void
    {
        $this->info(sprintf(
            '%d run(s) selected; %d held back for unsettled spans.',
            count($selection['runs']),
            count($selection['held']),
        ));

        if ($selection['held'] !== [] && (bool) $this->option('show-skipped')) {
            $this->table(['Processing ID', 'Held because'], $selection['held']);
        }
    }

    /**
     * @param  list<SermonTranscriptSpanRepairEntry>  $entries
     */
    private function reportEntries(array $entries): void
    {
        $counts = [];
        $rows = [];

        foreach ($entries as $entry) {
            $counts[$entry->disposition] = ($counts[$entry->disposition] ?? 0) + 1;

            if ($entry->disposition === HistoricSermonTranscriptSpanRepair::DISPOSITION_UNRESOLVED) {
                $rows[] = [$entry->processingId, $entry->disposition, (string) $entry->reason];
            }
        }

        foreach ($counts as $disposition => $count) {
            $this->line(sprintf('  %-18s %d', $disposition, $count));
        }

        if ($rows !== []) {
            $this->table(['Processing ID', 'Disposition', 'Reason'], $rows);
        }
    }
}
