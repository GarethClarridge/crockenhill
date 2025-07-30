<?php

namespace App\HealthChecks;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageHealthCheck implements Arrayable
{
  /**
   * The name of the health check.
   */
  public function name(): string
  {
    return 'storage-accessibility';
  }

  /**
   * Run the health check.
   */
  public function run(): array
  {
    try {
      $checks = [];
      $overallStatus = 'healthy';
      $issues = [];

      // Check public disk (for sermon files)
      $publicCheck = $this->checkDisk('public');
      $checks['public_disk'] = $publicCheck;
      if ($publicCheck['status'] !== 'healthy') {
        $overallStatus = 'degraded';
        $issues = array_merge($issues, $publicCheck['issues'] ?? []);
      }

      // Check local disk (for transcripts)
      $localCheck = $this->checkDisk('local');
      $checks['local_disk'] = $localCheck;
      if ($localCheck['status'] !== 'healthy') {
        $overallStatus = 'degraded';
        $issues = array_merge($issues, $localCheck['issues'] ?? []);
      }

      // Check specific directories
      $directoryChecks = $this->checkDirectories();
      $checks['directories'] = $directoryChecks;
      if ($directoryChecks['status'] !== 'healthy') {
        $overallStatus = 'degraded';
        $issues = array_merge($issues, $directoryChecks['issues'] ?? []);
      }

      return [
        'status' => $overallStatus,
        'message' => empty($issues) ? 'All storage systems accessible' : implode(', ', $issues),
        'checks' => $checks,
        'issues' => $issues,
        'timestamp' => now()->toISOString(),
      ];
    } catch (\Exception $e) {
      Log::warning('Storage health check failed', [
        'error' => $e->getMessage(),
      ]);

      return [
        'status' => 'error',
        'message' => 'Failed to check storage health: ' . $e->getMessage(),
        'timestamp' => now()->toISOString(),
      ];
    }
  }

  /**
   * Check a specific storage disk.
   */
  private function checkDisk(string $diskName): array
  {
    try {
      $storage = Storage::disk($diskName);
      $status = 'healthy';
      $issues = [];

      // Check if storage is accessible
      if (!$storage->exists('.')) {
        $status = 'error';
        $issues[] = "Storage disk '{$diskName}' is not accessible";
        return [
          'status' => $status,
          'issues' => $issues,
        ];
      }

      // Test write capability
      $testFile = 'health-check-' . time() . '.txt';
      try {
        $storage->put($testFile, 'health check test');
        $storage->delete($testFile);
      } catch (\Exception $e) {
        $status = 'degraded';
        $issues[] = "Storage disk '{$diskName}' write test failed: " . $e->getMessage();
      }

      return [
        'status' => $status,
        'disk' => $diskName,
        'issues' => $issues,
      ];
    } catch (\Exception $e) {
      return [
        'status' => 'error',
        'disk' => $diskName,
        'issues' => ["Failed to check disk '{$diskName}': " . $e->getMessage()],
      ];
    }
  }

  /**
   * Check specific directories used by sermon processing.
   */
  private function checkDirectories(): array
  {
    $directories = [
      'transcripts' => 'local',
      'sermons' => 'public',
    ];

    $status = 'healthy';
    $issues = [];
    $results = [];

    foreach ($directories as $dir => $disk) {
      try {
        $storage = Storage::disk($disk);
        $exists = $storage->exists($dir);

        if (!$exists) {
          // Try to create the directory
          try {
            $storage->makeDirectory($dir);
            $results[$dir] = [
              'status' => 'healthy',
              'message' => 'Directory created successfully',
            ];
          } catch (\Exception $e) {
            $status = 'degraded';
            $issues[] = "Cannot create directory '{$dir}' on disk '{$disk}'";
            $results[$dir] = [
              'status' => 'error',
              'message' => 'Directory missing and cannot be created',
            ];
          }
        } else {
          $results[$dir] = [
            'status' => 'healthy',
            'message' => 'Directory exists and accessible',
          ];
        }
      } catch (\Exception $e) {
        $status = 'error';
        $issues[] = "Failed to check directory '{$dir}': " . $e->getMessage();
        $results[$dir] = [
          'status' => 'error',
          'message' => $e->getMessage(),
        ];
      }
    }

    return [
      'status' => $status,
      'directories' => $results,
      'issues' => $issues,
    ];
  }

  /**
   * Convert the health check to an array.
   */
  public function toArray(): array
  {
    return $this->run();
  }
}
