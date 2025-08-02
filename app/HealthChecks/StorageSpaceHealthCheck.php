<?php

namespace App\HealthChecks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Illuminate\Support\Facades\Storage;

class StorageSpaceHealthCheck extends Check
{
    public function run(): Result
    {
        $result = Result::make();

        try {
            $checks = [
                'livestreams' => storage_path('app/livestreams'),
                'temp' => storage_path('app/temp'),
                'sermons' => storage_path('app/sermons'),
                'logs' => storage_path('logs'),
            ];

            $warnings = [];
            $errors = [];
            $totalUsed = 0;
            $totalAvailable = 0;

            foreach ($checks as $name => $path) {
                $usage = $this->getDirectoryUsage($path);
                $totalUsed += $usage['used_bytes'];
                $totalAvailable += $usage['available_bytes'];

                $usagePercent = $usage['available_bytes'] > 0 
                    ? ($usage['used_bytes'] / ($usage['used_bytes'] + $usage['available_bytes'])) * 100 
                    : 0;

                if ($usagePercent > 95) {
                    $errors[] = "{$name}: {$usage['used_formatted']} used, {$usage['available_formatted']} available ({$usagePercent:.1f}% full)";
                } elseif ($usagePercent > 85) {
                    $warnings[] = "{$name}: {$usage['used_formatted']} used, {$usage['available_formatted']} available ({$usagePercent:.1f}% full)";
                }

                $result->meta([
                    "{$name}_used" => $usage['used_formatted'],
                    "{$name}_available" => $usage['available_formatted'],
                    "{$name}_usage_percent" => round($usagePercent, 1),
                ]);
            }

            // Check sermon disk if configured
            $sermonDisk = config('livestream-processing.sermon_disk');
            if ($sermonDisk && $sermonDisk !== 'local') {
                $sermonDiskUsage = $this->checkDiskUsage($sermonDisk);
                $result->meta(['sermon_disk_status' => $sermonDiskUsage]);
            }

            if (!empty($errors)) {
                return $result->failed("Critical storage space issues: " . implode('; ', $errors));
            }

            if (!empty($warnings)) {
                return $result->warning("Storage space warnings: " . implode('; ', $warnings));
            }

            $totalUsedFormatted = $this->formatBytes($totalUsed);
            $result->meta(['total_used' => $totalUsedFormatted]);

            return $result->ok("Storage space is healthy (total used: {$totalUsedFormatted})");

        } catch (\Exception $e) {
            return $result->failed("Storage health check error: " . $e->getMessage());
        }
    }

    private function getDirectoryUsage(string $path): array
    {
        if (!is_dir($path)) {
            // Create directory if it doesn't exist
            mkdir($path, 0755, true);
        }

        $used = $this->getDirectorySize($path);
        $available = disk_free_space($path);

        return [
            'used_bytes' => $used,
            'available_bytes' => $available ?: 0,
            'used_formatted' => $this->formatBytes($used),
            'available_formatted' => $this->formatBytes($available ?: 0),
        ];
    }

    private function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function checkDiskUsage(string $diskName): array
    {
        try {
            $disk = Storage::disk($diskName);
            
            // This is basic - some disks may not support size queries
            return [
                'status' => 'accessible',
                'disk_name' => $diskName,
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'disk_name' => $diskName,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}