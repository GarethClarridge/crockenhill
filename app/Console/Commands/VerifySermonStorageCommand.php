<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sermon;
use App\Services\SermonStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class VerifySermonStorageCommand extends Command
{
    protected $signature = 'sermons:verify-storage {--disk=do_spaces}';

    protected $description = 'Verify that all sermon files are accessible on the specified disk';

    public function handle(): int
    {
        $disk = $this->option('disk');
        $storageService = app(SermonStorageService::class);

        $sermons = Sermon::all();
        $missing = [];
        $accessible = 0;
        $totalSize = 0;

        $this->info("Verifying {$sermons->count()} sermons on {$disk} disk...");

        $progressBar = $this->output->createProgressBar($sermons->count());
        $progressBar->start();

        foreach ($sermons as $sermon) {
            $fileInfo = $storageService->getSermonFileInfo($sermon);

            if (Storage::disk($disk)->exists($fileInfo['path'])) {
                $accessible++;
                try {
                    $size = Storage::disk($disk)->size($fileInfo['path']);
                    $totalSize += $size;
                } catch (\Exception $e) {
                    // Size calculation failed, but file exists
                }
            } else {
                $missing[] = [
                    'id' => $sermon->id,
                    'title' => $sermon->title,
                    'filename' => $sermon->audio_file_path,
                    'expected_path' => $fileInfo['path'],
                    'pattern' => $fileInfo['type'],
                ];
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->info("✓ Accessible files: {$accessible}");

        if ($totalSize > 0) {
            $this->info('✓ Total size: '.$this->formatBytes($totalSize));
        }

        if (count($missing) > 0) {
            $this->error('✗ Missing files: '.count($missing));
            $this->table(
                ['ID', 'Title', 'Filename', 'Expected Path', 'Pattern'],
                $missing
            );

            // Group missing files by pattern
            $patternCounts = array_count_values(array_column($missing, 'pattern'));
            $this->newLine();
            $this->info('Missing files by pattern:');
            foreach ($patternCounts as $pattern => $count) {
                $this->line("  {$pattern}: {$count}");
            }
        } else {
            $this->info('✓ All sermon files are accessible!');
        }

        // Display storage statistics
        $this->newLine();
        $this->displayStorageStats($storageService);

        return count($missing) > 0 ? 1 : 0;
    }

    private function displayStorageStats(SermonStorageService $storageService): void
    {
        $stats = $storageService->getStorageStats();

        $this->info('Storage Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Sermons', $stats['total_sermons']],
                ['Legacy Pattern', $stats['patterns']['legacy']],
                ['Storage Pattern', $stats['patterns']['storage']],
                ['Processing Pattern', $stats['patterns']['processing']],
                ['Total Size', $this->formatBytes($stats['total_size'])],
                ['Missing Files', $stats['missing_files']],
            ]
        );

        if (! empty($stats['disks'])) {
            $this->newLine();
            $this->info('Files by Disk:');
            $diskData = [];
            foreach ($stats['disks'] as $disk => $data) {
                $diskData[] = [
                    $disk,
                    $data['count'],
                    $this->formatBytes($data['size']),
                    $data['missing'],
                ];
            }
            $this->table(
                ['Disk', 'Files', 'Size', 'Missing'],
                $diskData
            );
        }
    }

    private function formatBytes(int $bytes): string
    {
        return Number::fileSize($bytes, precision: 2);
    }
}
