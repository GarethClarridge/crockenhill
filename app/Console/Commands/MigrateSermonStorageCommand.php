<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SermonStorageMaintenanceService;
use Illuminate\Console\Command;

class MigrateSermonStorageCommand extends Command
{
    protected $signature = 'sermons:migrate-storage
                           {--target=do_spaces : Target disk}
                           {--pattern= : Specific pattern (legacy|storage|processing)}
                           {--dry-run : Preview migration without executing}
                           {--batch-size=25 : Files per batch}
                           {--delay=100 : Milliseconds to pause between remote uploads}';

    protected $description = 'Migrate sermon files from different storage patterns to a target disk';

    public function handle(SermonStorageMaintenanceService $storageMaintenanceService): int
    {
        $targetDisk = $this->option('target');
        $specificPattern = $this->option('pattern');
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        $delayMs = (int) $this->option('delay');

        if (! is_string($targetDisk)) {
            $this->error('Invalid target disk.');

            return 1;
        }

        $this->info("Starting sermon storage migration to: {$targetDisk}");
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be actually migrated');
        }

        $patterns = $specificPattern ? [$specificPattern] : ['legacy', 'storage', 'processing'];
        $progressBar = $dryRun
            ? null
            : $this->output->createProgressBar($storageMaintenanceService->countStorageCandidates($patterns));

        if ($progressBar !== null) {
            $progressBar->start();
        }

        $result = $storageMaintenanceService->migrateStorage(
            $targetDisk,
            $patterns,
            (bool) $dryRun,
            $batchSize,
            $delayMs,
            function (array $_item) use ($progressBar): void {
                $progressBar?->advance();
            }
        );

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->newLine(2);
        }

        foreach ($result['items'] as $item) {
            if ($dryRun || in_array($item['status'], ['error', 'missing'], true)) {
                $this->line($item['label']);
            }
        }

        $summary = $result['summary'];

        $this->info(sprintf(
            'Migration completed! Examined: %d, Migrated: %d, Skipped: %d, Missing: %d, Failed: %d',
            $summary['examined'],
            $summary['migrated'],
            $summary['skipped'],
            $summary['missing'],
            $summary['failed'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
