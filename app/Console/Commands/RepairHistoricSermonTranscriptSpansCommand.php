<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Jobs\ProcessTranscriptWithAI;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\HistoricMedia\HistoricSermonTranscriptSpanRepair;
use App\Services\HistoricMedia\SermonTranscriptSpanRepairEntry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * Repairs sermon transcripts that were sliced to the extraction plan's outer
 * bounds rather than to the spans the media was actually cut from.
 *
 * Membership is re-derived on every run rather than read from a stored list:
 * the affected set grows while the pass continues, and shrinks as runs are
 * repaired. A repaired run reports as "already repaired" afterwards, so an
 * interrupted pass resumes by simply running the command again.
 *
 * The transcript repair calls no provider. `--reanalyse` does — it re-dispatches
 * {@see ProcessTranscriptWithAI} for the runs whose text actually changed, since
 * their banked analysis was derived from the contaminated transcript. That job
 * preserves curated titles, references and series on its own, so re-running it
 * fills the derived fields without overwriting a human decision.
 *
 * Deletion trigger: delete once every affected historic run is repaired and the
 * Phase 8 closeout retention window has expired.
 */
class RepairHistoricSermonTranscriptSpansCommand extends Command
{
    protected $signature = 'historic-import:repair-sermon-transcript-spans
                            {--operation= : Restrict to this historic operation ID}
                            {--processing-id=* : Restrict to these exact processing IDs}
                            {--limit= : Inspect at most this many runs, oldest first}
                            {--execute : Write the repaired transcripts (default: dry run)}
                            {--reanalyse : Also re-dispatch AI analysis for the repaired runs}
                            {--show-unaffected : List runs needing no repair as well}';

    protected $description = 'Repair historic sermon transcripts that included material between the extracted spans';

    public function handle(
        HistoricSermonTranscriptSpanRepair $repair,
        HistoricProcessingThroughput $throughput,
    ): int {
        try {
            $execute = (bool) $this->option('execute');
            $reanalyse = (bool) $this->option('reanalyse');

            if ($reanalyse && ! $execute) {
                throw new RuntimeException('--reanalyse requires --execute; analysis must not be re-dispatched against an unrepaired transcript.');
            }

            $runs = $this->runsQuery()->get();

            if ($runs->isEmpty()) {
                $this->warn('No completed runs matched this selection.');

                return self::SUCCESS;
            }

            $entries = $repair->inspect($runs);
            $this->report($entries);

            $repairable = array_values(array_filter(
                $entries,
                static fn (SermonTranscriptSpanRepairEntry $entry): bool => $entry->isRepairable(),
            ));

            if (! $execute) {
                $this->warn('DRY RUN: nothing was written.');
                $this->warn(sprintf('%d transcript(s) would be repaired.', count($repairable)));
                $this->line('Re-run with --execute for this exact selection.');

                return self::SUCCESS;
            }

            $totals = $repair->apply($entries);
            $this->info("Repaired {$totals['repaired']} transcript(s).");

            foreach ($totals['failures'] as $failure) {
                $this->error($failure);
            }

            if ($reanalyse) {
                $this->dispatchReanalysis($repairable, $totals['failures'], $throughput);
            } elseif ($totals['repaired'] > 0) {
                $this->warn('The analysis banked for these sermons still derives from the old transcript. Re-run with --execute --reanalyse to refresh it.');
            }

            return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<SermonTranscriptSpanRepairEntry>  $entries
     */
    private function report(array $entries): void
    {
        $showUnaffected = (bool) $this->option('show-unaffected');

        $rows = [];
        $counts = [];

        foreach ($entries as $entry) {
            $counts[$entry->disposition] = ($counts[$entry->disposition] ?? 0) + 1;

            if (! $showUnaffected && $entry->disposition === HistoricSermonTranscriptSpanRepair::DISPOSITION_UNAFFECTED) {
                continue;
            }

            $rows[] = [
                $entry->processingId,
                $entry->sermonId === null ? '-' : (string) $entry->sermonId,
                (string) $entry->spanCount,
                $entry->currentLength === null ? '-' : (string) $entry->currentLength,
                $entry->repairedLength === null ? '-' : (string) $entry->repairedLength,
                $this->removedColumn($entry),
                $entry->disk ?? '-',
                $entry->disposition.($entry->reason === null ? '' : ' ('.$entry->reason.')'),
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Processing ID', 'Sermon', 'Spans', 'Current', 'Repaired', 'Removed', 'Disk', 'Disposition'],
                $rows,
            );
        }

        ksort($counts);

        foreach ($counts as $disposition => $count) {
            $this->line(sprintf('%-20s %d', $disposition, $count));
        }
    }

    private function removedColumn(SermonTranscriptSpanRepairEntry $entry): string
    {
        $removed = $entry->removedLength();

        if ($removed === null || $entry->currentLength === null || $entry->currentLength <= 0) {
            return '-';
        }

        return sprintf('%d (%.1f%%)', $removed, 100 * $removed / $entry->currentLength);
    }

    /**
     * @param  list<SermonTranscriptSpanRepairEntry>  $repairable
     * @param  list<string>  $failures
     */
    private function dispatchReanalysis(array $repairable, array $failures, HistoricProcessingThroughput $throughput): void
    {
        $failedIds = [];

        foreach ($failures as $failure) {
            $failedIds[strtok($failure, ':')] = true;
        }

        $queue = $throughput->queueForClass(ProcessTranscriptWithAI::class);
        $dispatched = 0;

        foreach ($repairable as $entry) {
            if (isset($failedIds[$entry->processingId])) {
                continue;
            }

            $run = MediaProcessingLog::query()->find($entry->logId);

            if ($run instanceof MediaProcessingLog) {
                ProcessTranscriptWithAI::dispatch($run)->onQueue($queue);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} re-analysis job(s) onto the {$queue} queue.");
    }

    /**
     * @return Builder<MediaProcessingLog>
     */
    private function runsQuery(): Builder
    {
        $query = MediaProcessingLog::query()
            ->with('sermon')
            ->where('status', ProcessingStatus::Completed)
            ->whereNull('superseded_at')
            ->orderBy('id');

        $operationId = $this->option('operation');

        if (is_string($operationId) && trim($operationId) !== '') {
            $operation = HistoricImportOperation::query()->where('operation_id', trim($operationId))->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('The named historic operation does not exist.');
            }

            $query->where('historic_import_operation_id', $operation->id);
        }

        $processingIds = array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $this->option('processing-id')),
        ));

        if ($processingIds !== []) {
            $query->whereIn('processing_id', $processingIds);
        }

        $limit = $this->option('limit');

        if (is_string($limit) && trim($limit) !== '') {
            if ((int) $limit < 1) {
                throw new RuntimeException('The --limit option must be a positive integer.');
            }

            $query->limit((int) $limit);
        }

        return $query;
    }
}
