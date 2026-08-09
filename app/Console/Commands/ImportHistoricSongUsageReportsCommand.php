<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Song\HistoricSongUsageReportImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-shot import for the 2026-08-09 hymn reconciliation workbook.
 *
 * Deletion trigger: remove this command and its workbook-reader/importer services after the
 * production import has been reconciled, backed up and signed off in the historic archive plan.
 */
#[Signature('service-tracking:import-historic-song-usage-reports
    {--path=storage/scratch/outputs/hymn-reconciliation-2026-08-09/hymn-service-song-reconciliation.xlsx : Reconciliation workbook path}
    {--import : Persist reports; without this option the command is read-only}')]
#[Description('Import date-only historic song usage as evidence without inventing morning/evening services')]
class ImportHistoricSongUsageReportsCommand extends Command
{
    public function handle(HistoricSongUsageReportImporter $importer): int
    {
        $path = (string) $this->option('path');
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        try {
            $metrics = $importer->import($path, (bool) $this->option('import'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($metrics['dry_run']) {
            $this->warn('Dry run: no rows were written.');
        }

        $this->table(['Metric', 'Count'], [
            ['Rows read', (string) $metrics['rows_read']],
            ['Catalogue resolved', (string) $metrics['resolved']],
            ['Unresolved for later matching', (string) $metrics['unresolved']],
            ['Created', (string) $metrics['created']],
            ['Already present', (string) $metrics['existing']],
        ]);

        return self::SUCCESS;
    }
}
