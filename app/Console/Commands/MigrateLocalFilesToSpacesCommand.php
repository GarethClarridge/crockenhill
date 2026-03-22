<?php

namespace App\Console\Commands;

use App\Services\SermonStorageMaintenanceService;
use Illuminate\Console\Command;

class MigrateLocalFilesToSpacesCommand extends Command
{
    protected $signature = 'sermons:migrate-local-files
                           {--dry-run : Preview migration without executing}
                           {--force : Overwrite files that already exist on the target disk}';

    protected $description = 'Migrate local sermon files from public disk to DigitalOcean Spaces';

    public function handle(SermonStorageMaintenanceService $storageMaintenanceService): int
    {
        $dryRun = $this->option('dry-run');
        $force = (bool) $this->option('force');
        $progressBar = $dryRun
            ? null
            : $this->output->createProgressBar($storageMaintenanceService->countLocalFiles());

        if ($progressBar !== null) {
            $progressBar->start();
        }

        $result = $storageMaintenanceService->migrateLocalFiles(
            (bool) $dryRun,
            force: $force,
            progress: function (array $_item) use ($progressBar): void {
                $progressBar?->advance();
            }
        );

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->newLine(2);
        }
        $summary = $result['summary'];

        $this->info('Found '.$summary['examined'].' files to migrate');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be actually migrated');
        } elseif (! $force) {
            $this->line('Existing remote files are skipped by default. Use --force to overwrite them.');
        }

        foreach ($result['items'] as $item) {
            if ($dryRun || in_array($item['status'], ['error', 'skip'], true)) {
                $this->line($item['label']);
            }
        }

        $this->newLine();
        $this->info('Migration complete!');
        $this->info("Examined: {$summary['examined']}");
        $this->info("Migrated: {$summary['migrated']}");
        $this->info("Skipped: {$summary['skipped']}");
        $this->info("Missing: {$summary['missing']}");
        $this->info("Failed: {$summary['failed']}");

        return $summary['failed'] > 0 ? 1 : 0;
    }
}
