<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class CleanupOrphanedTempFiles extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:cleanup-temp-files
                          {--hours=24 : Delete files older than this many hours}
                          {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up orphaned temporary files from media processing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        $cutoffTime = now()->subHours($hours);

        $this->info("Cleaning up temporary files older than {$hours} hours (cutoff: {$cutoffTime->toDateTimeString()})");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $totalDeleted = 0;
        $totalSize = 0;

        // Clean up livestream/temp directory
        $this->info('');
        $this->info('Cleaning livestream/temp directory...');
        [$deleted, $size] = $this->cleanupDirectory('livestream/temp', $cutoffTime, $dryRun);
        $totalDeleted += $deleted;
        $totalSize += $size;

        // Clean up livewire-tmp directory (Livewire's default temp uploads)
        $this->info('');
        $this->info('Cleaning livewire-tmp directory...');
        [$deleted, $size] = $this->cleanupDirectory('livewire-tmp', $cutoffTime, $dryRun);
        $totalDeleted += $deleted;
        $totalSize += $size;

        // Clean up temp/livewire-upload directory (used by MediaUpload component)
        $this->info('');
        $this->info('Cleaning temp/livewire-upload directory...');
        [$deleted, $size] = $this->cleanupDirectory('temp/livewire-upload', $cutoffTime, $dryRun);
        $totalDeleted += $deleted;
        $totalSize += $size;

        // Clean up temp/thumbnails directory (used by ThumbnailGenerationService for S3 video downloads)
        $this->info('');
        $this->info('Cleaning temp/thumbnails directory...');
        [$deleted, $size] = $this->cleanupDirectory('temp/thumbnails', $cutoffTime, $dryRun);
        $totalDeleted += $deleted;
        $totalSize += $size;

        // Clean up temp/audio_extraction directory (used by VideoExtractionService)
        $this->info('');
        $this->info('Cleaning temp/audio_extraction directory...');
        [$deleted, $size] = $this->cleanupDirectory('temp/audio_extraction', $cutoffTime, $dryRun);
        $totalDeleted += $deleted;
        $totalSize += $size;

        // Clean up temp/video-processing directory (used by UnifiedMediaProcessor)
        $this->info('');
        $this->info('Cleaning temp/video-processing directory...');
        [$deleted, $size] = $this->cleanupDirectory('temp/video-processing', $cutoffTime, $dryRun);
        $totalDeleted += $deleted;
        $totalSize += $size;

        // Clean up temp/audio-validation directory (used by ValidateAudioFile job)
        $this->info('');
        $this->info('Cleaning temp/audio-validation directory...');
        [$deleted, $size] = $this->cleanupDirectory('temp/audio-validation', $cutoffTime, $dryRun);
        $totalDeleted += $deleted;
        $totalSize += $size;

        // Clean up temp directory (general temp files, only direct children to avoid recursion)
        $this->info('');
        $this->info('Cleaning temp directory (direct files only)...');
        [$deleted, $size] = $this->cleanupDirectory('temp', $cutoffTime, $dryRun, 1);
        $totalDeleted += $deleted;
        $totalSize += $size;

        $this->info('');
        $this->info('========================================');
        $totalSizeDisplay = Number::fileSize($totalSize, precision: 2);

        if ($dryRun) {
            $this->info("Would delete {$totalDeleted} files ({$totalSizeDisplay})");
        } else {
            $this->info("Deleted {$totalDeleted} files ({$totalSizeDisplay})");
        }
        $this->info('========================================');

        // Log the cleanup
        if (! $dryRun) {
            Log::info('Orphaned temp files cleaned up', [
                'files_deleted' => $totalDeleted,
                'size_bytes' => $totalSize,
                'size_display' => $totalSizeDisplay,
                'cutoff_hours' => $hours,
                'cutoff_time' => $cutoffTime->toDateTimeString(),
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Clean up files in a specific directory
     *
     * @return array<int, int> [files_deleted, total_size]
     */
    private function cleanupDirectory(string $directory, \Carbon\Carbon $cutoffTime, bool $dryRun, int $depth = 0): array
    {
        $disk = Storage::disk('local');
        $deletedCount = 0;
        $totalSize = 0;

        try {
            if (! $disk->exists($directory)) {
                $this->line("  Directory does not exist: {$directory}");

                return [0, 0];
            }

            // Get files in the directory (optionally recursive)
            $files = $depth > 0 ? $disk->files($directory) : $disk->allFiles($directory);

            foreach ($files as $file) {
                try {
                    $lastModified = $disk->lastModified($file);
                    $fileTime = \Carbon\Carbon::createFromTimestamp($lastModified);

                    if ($fileTime->lt($cutoffTime)) {
                        $fileSize = $disk->size($file);
                        $totalSize += $fileSize;

                        $fileSizeDisplay = Number::fileSize($fileSize, precision: 2);
                        $age = $fileTime->diffForHumans();

                        if ($dryRun) {
                            $this->line("  [DRY RUN] Would delete: {$file} ({$fileSizeDisplay}, {$age})");
                        } else {
                            $disk->delete($file);
                            $deletedCount++;
                            $this->line("  Deleted: {$file} ({$fileSizeDisplay}, {$age})");
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("  Failed to process file {$file}: {$e->getMessage()}");
                }
            }

            if ($deletedCount === 0 && count($files) === 0) {
                $this->line("  No files found in {$directory}");
            } elseif ($deletedCount === 0) {
                $this->line("  No files older than cutoff time in {$directory}");
            }
        } catch (\Exception $e) {
            $this->error("  Failed to clean directory {$directory}: {$e->getMessage()}");
        }

        return [$deletedCount, $totalSize];
    }
}
