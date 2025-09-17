<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sermon;
use App\Services\SermonStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;

class MigrateSermonStorageCommand extends Command
{
    protected $signature = 'sermons:migrate-storage
                           {--target=do_spaces : Target disk}
                           {--pattern= : Specific pattern (legacy|storage|processing)}
                           {--dry-run : Preview migration without executing}
                           {--batch-size=25 : Files per batch}';

    protected $description = 'Migrate sermon files from different storage patterns to a target disk';

    public function handle(): int
    {
        $targetDisk = $this->option('target');
        $specificPattern = $this->option('pattern');
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        $this->info("Starting sermon storage migration to: {$targetDisk}");
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be actually migrated');
        }

        $patterns = $specificPattern ? [$specificPattern] : ['legacy', 'storage', 'processing'];

        foreach ($patterns as $pattern) {
            $this->migratePattern($pattern, $targetDisk, $dryRun, $batchSize);
        }

        $this->info('Migration completed!');
        return 0;
    }

    private function migratePattern(string $pattern, string $targetDisk, bool $dryRun, int $batchSize): void
    {
        $query = Sermon::query();

        switch ($pattern) {
            case 'legacy':
                $sermons = $query->whereNotNull('filetype')
                    ->whereRaw('filename NOT LIKE "%/%"')
                    ->get();
                $this->migrateLegacySermons($sermons, $targetDisk, $dryRun, $batchSize);
                break;

            case 'storage':
                $sermons = $query->whereRaw('filename LIKE "%/%"')
                    ->whereNull('filetype')
                    ->get();
                $this->migrateStorageSermons($sermons, $targetDisk, $dryRun, $batchSize);
                break;

            case 'processing':
                $sermons = $query->whereNotNull('transcript_path')
                    ->orWhereNotNull('video_file_path')
                    ->get();
                $this->migrateProcessingSermons($sermons, $targetDisk, $dryRun, $batchSize);
                break;
        }
    }

    private function migrateLegacySermons($sermons, string $targetDisk, bool $dryRun, int $batchSize): void
    {
        $this->info("Migrating {$sermons->count()} legacy sermons");

        if ($dryRun) {
            foreach ($sermons as $sermon) {
                $sourcePath = public_path("media/sermons/{$sermon->filename}.{$sermon->filetype}");
                $targetPath = "legacy/sermons/{$sermon->filename}.{$sermon->filetype}";
                $this->line("Would migrate: {$sourcePath} → {$targetPath}");
            }
            return;
        }

        $chunks = $sermons->chunk($batchSize);
        $progressBar = $this->output->createProgressBar($sermons->count());
        $progressBar->start();

        foreach ($chunks as $chunk) {
            foreach ($chunk as $sermon) {
                try {
                    $sourcePath = public_path("media/sermons/{$sermon->filename}.{$sermon->filetype}");
                    $targetPath = "legacy/sermons/{$sermon->filename}.{$sermon->filetype}";

                    if (file_exists($sourcePath)) {
                        $content = file_get_contents($sourcePath);
                        Storage::disk($targetDisk)->put($targetPath, $content);

                        // Verify upload with retry for eventual consistency
                        $verified = false;
                        for ($attempt = 1; $attempt <= 3; $attempt++) {
                            if (Storage::disk($targetDisk)->exists($targetPath)) {
                                $verified = true;
                                break;
                            }
                            if ($attempt < 3) {
                                usleep(500000); // Wait 0.5 seconds before retry
                            }
                        }

                        if ($verified) {
                            $progressBar->advance();
                        } else {
                            $this->error("Failed to verify upload after 3 attempts: {$sermon->filename}.{$sermon->filetype}");
                        }
                    } else {
                        $this->warn("Source file not found: {$sourcePath}");
                        $progressBar->advance();
                    }
                } catch (Exception $e) {
                    $this->error("Failed to migrate {$sermon->filename}: " . $e->getMessage());
                    $progressBar->advance();
                }
            }
        }

        $progressBar->finish();
        $this->newLine();
    }

    private function migrateStorageSermons($sermons, string $targetDisk, bool $dryRun, int $batchSize): void
    {
        $this->info("Migrating {$sermons->count()} storage sermons");

        if ($dryRun) {
            foreach ($sermons as $sermon) {
                $this->line("Would migrate storage sermon: {$sermon->filename}");
            }
            return;
        }

        $progressBar = $this->output->createProgressBar($sermons->count());
        $progressBar->start();

        $chunks = $sermons->chunk($batchSize);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $sermon) {
                try {
                    $sourceDisk = 'public'; // Current storage location

                    if (Storage::disk($sourceDisk)->exists($sermon->filename)) {
                        $content = Storage::disk($sourceDisk)->get($sermon->filename);
                        Storage::disk($targetDisk)->put($sermon->filename, $content);

                        // Verify upload with retry for eventual consistency
                        $verified = false;
                        for ($attempt = 1; $attempt <= 3; $attempt++) {
                            if (Storage::disk($targetDisk)->exists($sermon->filename)) {
                                $verified = true;
                                break;
                            }
                            if ($attempt < 3) {
                                usleep(500000); // Wait 0.5 seconds before retry
                            }
                        }

                        if ($verified) {
                            $progressBar->advance();
                        } else {
                            $this->error("Failed to verify upload after 3 attempts: {$sermon->filename}");
                        }
                    } else {
                        $this->warn("Source file not found: {$sermon->filename}");
                        $progressBar->advance();
                    }
                } catch (Exception $e) {
                    $this->error("Failed to migrate storage sermon {$sermon->filename}: " . $e->getMessage());
                    $progressBar->advance();
                }
            }
        }

        $progressBar->finish();
        $this->newLine();
    }

    private function migrateProcessingSermons($sermons, string $targetDisk, bool $dryRun, int $batchSize): void
    {
        $this->info("Migrating {$sermons->count()} processing sermons");

        if ($dryRun) {
            foreach ($sermons as $sermon) {
                $this->line("Would migrate processing sermon: {$sermon->filename}");
            }
            return;
        }

        $storageService = app(SermonStorageService::class);
        $progressBar = $this->output->createProgressBar($sermons->count());
        $progressBar->start();

        $chunks = $sermons->chunk($batchSize);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $sermon) {
                try {
                    $moved = $storageService->moveFile($sermon, $targetDisk);
                    if ($moved) {
                        $progressBar->advance();
                    } else {
                        $this->error("Failed to migrate processing sermon: {$sermon->filename}");
                    }
                } catch (Exception $e) {
                    $this->error("Failed to migrate processing sermon {$sermon->filename}: " . $e->getMessage());
                    $progressBar->advance();
                }
            }
        }

        $progressBar->finish();
        $this->newLine();
    }
}