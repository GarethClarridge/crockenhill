<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sermon;
use App\Services\SermonStorageService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
                    ->whereRaw('audio_file_path NOT LIKE "%/%"')
                    ->get();
                $this->migrateLegacySermons($sermons, $targetDisk, $dryRun, $batchSize);
                break;

            case 'storage':
                $sermons = $query->whereRaw('audio_file_path LIKE "%/%"')
                    ->whereNull('filetype')
                    ->get();
                $this->migrateStorageSermons($sermons, $targetDisk, $dryRun, $batchSize);
                break;

            case 'processing':
                $sermons = $query->whereNotNull('transcript_file_path')
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
                $sourcePath = "media/sermons/{$sermon->audio_file_path}.{$sermon->filetype}";
                $targetPath = "legacy/sermons/{$sermon->audio_file_path}.{$sermon->filetype}";
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
                    $sourcePath = "media/sermons/{$sermon->audio_file_path}.{$sermon->filetype}";
                    $targetPath = "legacy/sermons/{$sermon->audio_file_path}.{$sermon->filetype}";

                    if (Storage::disk('public_images')->exists($sourcePath)) {
                        // Skip if already exists
                        if (Storage::disk($targetDisk)->exists($targetPath)) {
                            $progressBar->advance();

                            continue;
                        }

                        $content = Storage::disk('public_images')->get($sourcePath);

                        // Add small delay between uploads to avoid rate limiting
                        usleep(100000); // 0.1 second delay

                        $uploadSuccessful = Storage::disk($targetDisk)->put($targetPath, $content);

                        if (! $uploadSuccessful) {
                            $this->error("Upload failed: {$sermon->audio_file_path}.{$sermon->filetype}");
                            $progressBar->advance();

                            continue;
                        }

                        // Verify upload with retry for eventual consistency
                        $uploadVerified = false;
                        for ($attempt = 1; $attempt <= 5; $attempt++) {
                            try {
                                $fileExists = Storage::disk($targetDisk)->exists($targetPath);
                                // @phpstan-ignore-next-line - fileExists can be true
                                if ($fileExists) {
                                    $uploadVerified = true;
                                    break;
                                }
                            } catch (\Exception $e) {
                                $this->warn("Verification attempt {$attempt} failed: {$e->getMessage()}");
                            }

                            if ($attempt < 5) {
                                usleep(1000000); // Wait 1 second before retry (increased)
                            }
                        }

                        // @phpstan-ignore-next-line - $uploadVerified can be true after successful verification
                        if ($uploadVerified) {
                            $progressBar->advance();
                        } else {
                            $this->error("Failed to verify upload after 5 attempts: {$sermon->audio_file_path}.{$sermon->filetype}");
                        }
                    } else {
                        $this->warn("Source file not found: {$sourcePath} on public_images disk");
                        $progressBar->advance();
                    }
                } catch (Exception $e) {
                    $this->error("Failed to migrate {$sermon->audio_file_path}: ".$e->getMessage());
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
                $this->line("Would migrate storage sermon: {$sermon->audio_file_path}");
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

                    if (Storage::disk($sourceDisk)->exists($sermon->audio_file_path)) {
                        $content = Storage::disk($sourceDisk)->get($sermon->audio_file_path);
                        Storage::disk($targetDisk)->put($sermon->audio_file_path, $content);

                        // Verify upload with retry for eventual consistency
                        $verified = false;
                        for ($attempt = 1; $attempt <= 3; $attempt++) {
                            if (Storage::disk($targetDisk)->exists($sermon->audio_file_path)) {
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
                            $this->error("Failed to verify upload after 3 attempts: {$sermon->audio_file_path}");
                        }
                    } else {
                        $this->warn("Source file not found: {$sermon->audio_file_path}");
                        $progressBar->advance();
                    }
                } catch (Exception $e) {
                    $this->error("Failed to migrate storage sermon {$sermon->audio_file_path}: ".$e->getMessage());
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
                $this->line("Would migrate processing sermon: {$sermon->audio_file_path}");
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
                        $this->error("Failed to migrate processing sermon: {$sermon->audio_file_path}");
                    }
                } catch (Exception $e) {
                    $this->error("Failed to migrate processing sermon {$sermon->audio_file_path}: ".$e->getMessage());
                    $progressBar->advance();
                }
            }
        }

        $progressBar->finish();
        $this->newLine();
    }
}
