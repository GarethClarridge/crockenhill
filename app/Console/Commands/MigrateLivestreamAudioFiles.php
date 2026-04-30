<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SermonStorageMaintenanceService;
use Illuminate\Console\Command;

class MigrateLivestreamAudioFiles extends Command
{
    protected $signature = 'sermons:migrate-livestream-audio 
                           {--dry-run : Show what would be migrated without making changes}
                           {--cleanup : Remove files from local storage after successful migration}';

    protected $description = 'Migrate sermon audio files from local to public storage (finds by filename pattern)';

    public function handle(SermonStorageMaintenanceService $storageMaintenanceService): int
    {
        $this->info('Searching for livestream sermons with misplaced audio files...');
        $this->info('Expected pattern: sermons/YYYY/MM/uuid.mp3');
        $this->newLine();

        $candidateCount = $storageMaintenanceService->countLivestreamAudioCandidates();

        if ($candidateCount === 0) {
            $this->warn('No livestream sermons found in database.');

            return 0;
        }

        $this->info("Found {$candidateCount} livestream sermons to check...");
        $this->newLine();

        $progressBar = $this->option('dry-run')
            ? null
            : $this->output->createProgressBar($candidateCount);

        if ($progressBar !== null) {
            $progressBar->start();
        }

        $result = $storageMaintenanceService->migrateLivestreamAudio(
            dryRun: (bool) $this->option('dry-run'),
            cleanup: (bool) $this->option('cleanup'),
            progress: function (array $_item) use ($progressBar): void {
                $progressBar?->advance();
            },
        );

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->newLine(2);
        }

        foreach ($result['items'] as $item) {
            if ($this->option('dry-run') || in_array($item['status'], ['error', 'missing'], true)) {
                $this->line($item['label']);
            }
        }

        // Summary
        $summary = $result['summary'];
        $this->info('Migration Summary:');
        $this->info('=================');
        $this->info("- Examined: {$summary['examined']} files");
        $this->info("- Migrated: {$summary['migrated']} files");
        $this->info("- Skipped: {$summary['skipped']} files");
        $this->info("- Missing: {$summary['missing']} files");
        $this->info("- Errors: {$summary['failed']} files");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('This was a dry run. Remove --dry-run to perform actual migration.');
        }

        return $summary['failed'] > 0 ? 1 : 0;
    }
}
