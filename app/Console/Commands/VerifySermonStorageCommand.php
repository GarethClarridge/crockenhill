<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sermon\SermonStorageMaintenanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class VerifySermonStorageCommand extends Command
{
    protected $signature = 'sermons:verify-storage {--disk=do_spaces}';

    protected $description = 'Verify that all sermon files are accessible on the specified disk';

    public function handle(SermonStorageMaintenanceService $storageMaintenanceService): int
    {
        $disk = $this->option('disk');
        $progressBar = $this->output->createProgressBar($storageMaintenanceService->countVerifiableSermons());
        $progressBar->start();
        $result = $storageMaintenanceService->verifyStorage((string) $disk, function (array $_item) use ($progressBar): void {
            $progressBar->advance();
        });
        $progressBar->finish();
        $this->newLine(2);
        $summary = $result['summary'];
        $missing = $result['missing'];

        $this->info('Verifying sermon files on '.$disk.' disk...');

        // Display results
        $this->info("✓ Accessible files: {$summary['accessible']}");

        if ($summary['total_size'] > 0) {
            $this->info('✓ Total size: '.$this->formatBytes($summary['total_size']));
        }

        if ($missing !== []) {
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
        $this->displayStorageStats($result['storage_stats']);

        return count($missing) > 0 ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function displayStorageStats(array $stats): void
    {
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
