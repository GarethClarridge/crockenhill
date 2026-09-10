<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricSourceDurationBackfill;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * Measure the archive recording behind every run that never recorded a source
 * duration, so its sections can be bounds-checked at all.
 *
 * P8-Q17's other half. `historic-import:screen-section-bounds` reports these
 * runs as unmeasurable rather than passing them, and this is what clears them.
 *
 * The archive root is named by the operator, as it is for the import itself:
 * the manifest records source paths relative to whatever root the pass was
 * dispatched against, and nothing in the database knows where that volume is
 * mounted today. An absolute path in the manifest is used as recorded and never
 * prefixed.
 *
 * Deletion trigger: delete once every historic run holding sections carries a
 * source duration and the Phase 8 release census has run, alongside
 * {@see HistoricSourceDurationBackfill}.
 */
class BackfillHistoricSourceDurationsCommand extends Command
{
    protected $signature = 'historic-import:backfill-source-durations
                            {--archive-root=/mnt/cbc-services : Root the manifest\'s relative source paths resolve against}
                            {--operation= : Restrict to this historic operation ID}
                            {--processing-id=* : Restrict to these exact processing IDs}
                            {--verify-hash : Prove byte-identity rather than matching the approved size}
                            {--execute : Record the measured durations (default: dry run)}';

    protected $description = 'Recover missing source durations for historic runs by probing their archive recordings';

    public function handle(HistoricSourceDurationBackfill $backfill): int
    {
        try {
            $archiveRoot = trim((string) $this->option('archive-root'));

            if ($archiveRoot === '' || ! is_dir($archiveRoot)) {
                /*
                 * Checked before anything is probed. An unmounted archive
                 * reports every file as absent, which reads exactly like a
                 * corpus that has been reaped — and would turn a detached drive
                 * into a clean "nothing is recoverable" report.
                 */
                throw new RuntimeException("The archive root {$archiveRoot} is not a readable directory. Is the drive mounted?");
            }

            $runs = $this->runsQuery()->get();

            if ($runs->isEmpty()) {
                $this->warn('No runs matched this selection.');

                return self::SUCCESS;
            }

            $entries = $backfill->inspect($runs, $archiveRoot, (bool) $this->option('verify-hash'));
            $this->report($entries);

            $measurable = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['disposition'] === HistoricSourceDurationBackfill::DispositionMeasurable,
            ));

            if (! (bool) $this->option('execute')) {
                $this->warn('DRY RUN: nothing was written.');
                $this->warn(sprintf('%d duration(s) would be recorded.', count($measurable)));
                $this->line('Re-run with --execute for this exact selection.');

                return self::SUCCESS;
            }

            $totals = $backfill->apply($entries);
            $this->info("Recorded {$totals['recorded']} duration(s); {$totals['unchanged']} already had one.");

            foreach ($totals['failures'] as $failure) {
                $this->error($failure);
            }

            return $totals['failures'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<array{log_id: int, processing_id: string, disposition: string, archive_path: string|null, measured_duration: float|null, measured_by?: 'header'|'packet_count', reason: string|null}>  $entries
     */
    private function report(array $entries): void
    {
        $rows = [];
        $counts = [];

        foreach ($entries as $entry) {
            $counts[$entry['disposition']] = ($counts[$entry['disposition']] ?? 0) + 1;

            if ($entry['disposition'] === HistoricSourceDurationBackfill::DispositionAlreadyRecorded) {
                continue;
            }

            $rows[] = [
                (string) $entry['log_id'],
                $entry['processing_id'],
                $entry['measured_duration'] === null ? '-' : sprintf('%.2f', $entry['measured_duration']),
                $entry['measured_by'] ?? '-',
                $entry['archive_path'] ?? '-',
                $entry['disposition'].($entry['reason'] === null ? '' : ' ('.$entry['reason'].')'),
            ];
        }

        if ($rows !== []) {
            $this->table(['Run', 'Processing ID', 'Duration', 'Measured by', 'Archive copy', 'Disposition'], $rows);
        }

        ksort($counts);

        foreach ($counts as $disposition => $count) {
            $this->line(sprintf('%-20s %d', $disposition, $count));
        }
    }

    /**
     * Runs holding sections but no usable duration.
     *
     * Sections are the point: a run with none is not owed a bounds check, and
     * probing the archive for it would spend drive time on a question nobody
     * asked.
     *
     * @return Builder<MediaProcessingLog>
     */
    private function runsQuery(): Builder
    {
        $query = MediaProcessingLog::query()
            ->whereHas('serviceSections')
            ->where(function (Builder $missing): void {
                $missing->whereNull('duration')->orWhere('duration', '<=', 0);
            })
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

        return $query;
    }
}
