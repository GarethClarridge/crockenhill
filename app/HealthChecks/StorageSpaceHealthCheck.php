<?php

namespace App\HealthChecks;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Storage;

class StorageSpaceHealthCheck implements Arrayable
{
    /**
     * The name of the health check.
     */
    public function name(): string
    {
        return 'storage-space';
    }

    public function run(): array
    {
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
                    $errors[] = sprintf('%s: %s used, %s available (%.1f%% full)',
                        $name, $usage['used_formatted'], $usage['available_formatted'], $usagePercent);
                } elseif ($usagePercent > 85) {
                    $warnings[] = sprintf('%s: %s used, %s available (%.1f%% full)',
                        $name, $usage['used_formatted'], $usage['available_formatted'], $usagePercent);
                }

                // Collecting metadata for final response
            }

            // Check sermon disk if configured
            $metadata = ['total_used' => $this->formatBytes($totalUsed)];
            $sermonDisk = config('livestream-processing.sermon_disk');
            if ($sermonDisk && $sermonDisk !== 'local') {
                $sermonDiskUsage = $this->checkDiskUsage($sermonDisk);
                $metadata['sermon_disk_status'] = $sermonDiskUsage;
            }

            if (! empty($errors)) {
                return [
                    'status' => 'error',
                    'message' => 'Critical storage space issues: '.implode('; ', $errors),
                    'metadata' => $metadata,
                    'timestamp' => now()->toISOString(),
                ];
            }

            if (! empty($warnings)) {
                return [
                    'status' => 'degraded',
                    'message' => 'Storage space warnings: '.implode('; ', $warnings),
                    'metadata' => $metadata,
                    'timestamp' => now()->toISOString(),
                ];
            }

            $totalUsedFormatted = $this->formatBytes($totalUsed);

            return [
                'status' => 'healthy',
                'message' => "Storage space is healthy (total used: {$totalUsedFormatted})",
                'metadata' => $metadata,
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Storage health check error: '.$e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Convert the health check to an array.
     */
    public function toArray(): array
    {
        return $this->run();
    }

    private function getDirectoryUsage(string $path): array
    {
        if (! is_dir($path)) {
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
        if (! is_dir($path)) {
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

        return round($bytes, 2).' '.$units[$pow];
    }
}
