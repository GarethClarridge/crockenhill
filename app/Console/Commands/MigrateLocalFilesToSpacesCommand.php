<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SermonStorageMaintenanceService;
use Illuminate\Console\Command;

class MigrateLocalFilesToSpacesCommand extends Command
{
    protected $signature = 'sermons:migrate-local-files
                           {--dry-run : Preview migration without executing}
                           {--force : Overwrite files that already exist on the target disk}
                           {--referenced-sermon-audio : Only migrate unique audio files referenced by sermon records}
                           {--path-prefix= : Restrict migrations to a relative storage path prefix such as sermons/audio/2013}
                           {--start-after= : Resume after a previously processed relative storage path}';

    protected $description = 'Migrate local sermon files from public disk to DigitalOcean Spaces';

    public function handle(SermonStorageMaintenanceService $storageMaintenanceService): int
    {
        $dryRun = $this->option('dry-run');
        $force = (bool) $this->option('force');
        $referencedSermonAudio = (bool) $this->option('referenced-sermon-audio');
        $pathPrefix = $this->option('path-prefix');
        $startAfter = $this->option('start-after');
        $normalizedPathPrefix = is_string($pathPrefix) && trim($pathPrefix) !== ''
            ? trim(trim($pathPrefix), '/')
            : null;
        $normalizedStartAfter = is_string($startAfter) && trim($startAfter) !== ''
            ? trim(trim($startAfter), '/')
            : null;
        $progressBar = $dryRun
            ? null
            : $this->output->createProgressBar(
                $referencedSermonAudio
                    ? $storageMaintenanceService->countReferencedSermonAudioCandidates($normalizedPathPrefix, $normalizedStartAfter)
                    : $storageMaintenanceService->countLocalFiles(pathPrefix: $normalizedPathPrefix, startAfter: $normalizedStartAfter)
            );

        if ($progressBar !== null) {
            $progressBar->start();
        }

        $result = $referencedSermonAudio
            ? $storageMaintenanceService->migrateReferencedSermonAudio(
                dryRun: (bool) $dryRun,
                force: $force,
                pathPrefix: $normalizedPathPrefix,
                startAfter: $normalizedStartAfter,
                progress: function (array $_item) use ($progressBar): void {
                    $progressBar?->advance();
                }
            )
            : $storageMaintenanceService->migrateLocalFiles(
                dryRun: (bool) $dryRun,
                force: $force,
                pathPrefix: $normalizedPathPrefix,
                startAfter: $normalizedStartAfter,
                progress: function (array $_item) use ($progressBar): void {
                    $progressBar?->advance();
                }
            );

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->newLine(2);
        }
        $summary = $result['summary'];

        if ($referencedSermonAudio) {
            $this->line('Mode: sermon-referenced audio');
        } else {
            $this->line('Mode: directory sweep');
        }

        if ($normalizedPathPrefix !== null) {
            $this->line("Path prefix: {$normalizedPathPrefix}");
        }

        if ($normalizedStartAfter !== null) {
            $this->line("Start after: {$normalizedStartAfter}");
        }

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
