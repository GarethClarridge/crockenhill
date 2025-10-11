<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessingHealthServiceInterface;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ProcessingHealthService - System health monitoring and statistics
 *
 * Extracted from SermonProcessingService to follow Single Responsibility Principle.
 * Provides health monitoring capabilities for the media processing system.
 */
class ProcessingHealthService implements ProcessingHealthServiceInterface
{
    /**
     * Get processing statistics and metrics
     */
    public function getProcessingStatistics(): array
    {
        try {
            $stats = [
                'total_processed' => MediaProcessingLog::count(),
                'completed' => MediaProcessingLog::completed()->count(),
                'failed' => MediaProcessingLog::failed()->count(),
                'in_progress' => MediaProcessingLog::processing()->count(),
                'pending' => MediaProcessingLog::pending()->count(),
                'recent_activity' => MediaProcessingLog::recent()
                    ->with('sermon')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->map(function ($log) {
                        return [
                            'processing_id' => $log->processing_id,
                            'status' => $log->status->label(),
                            'current_step' => $log->current_step,
                            'sermon_title' => $log->sermon?->title,
                            'created_at' => $log->created_at->diffForHumans(),
                        ];
                    }),
            ];

            // Calculate success rate
            $total = $stats['total_processed'];
            $stats['success_rate'] = $total > 0 ? round(($stats['completed'] / $total) * 100, 2) : 0;

            return $stats;

        } catch (\Exception $e) {
            Log::error('Failed to generate processing statistics', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Failed to generate processing statistics',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get overall system health status
     */
    public function getSystemHealth(): array
    {
        try {
            $health = [
                'overall_status' => 'healthy',
                'checks' => [],
                'statistics' => $this->getProcessingStatistics(),
                'timestamp' => now()->toISOString(),
            ];

            // Check queue health
            $queueHealth = $this->checkQueueHealth();
            $health['checks']['queue'] = $queueHealth;

            // Check storage health
            $storageHealth = $this->checkStorageHealth();
            $health['checks']['storage'] = $storageHealth;

            // Check recent processing success rate
            $processingHealth = $this->checkProcessingHealth();
            $health['checks']['processing'] = $processingHealth;

            // Determine overall status
            $allHealthy = collect($health['checks'])->every(fn ($check) => $check['status'] === 'healthy');
            $health['overall_status'] = $allHealthy ? 'healthy' : 'degraded';

            return $health;

        } catch (\Exception $e) {
            Log::error('Failed to generate system health check', [
                'error' => $e->getMessage(),
            ]);

            return [
                'overall_status' => 'error',
                'message' => 'Failed to generate health check: '.$e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Check queue processing health
     */
    public function checkQueueHealth(): array
    {
        try {
            // Check if there are jobs stuck in processing for too long
            $stuckJobs = MediaProcessingLog::processing()
                ->where('updated_at', '<', now()->subHours(2))
                ->count();

            $pendingJobs = MediaProcessingLog::pending()->count();

            $status = 'healthy';
            $issues = [];

            if ($stuckJobs > 0) {
                $status = 'degraded';
                $issues[] = "{$stuckJobs} jobs appear to be stuck in processing";
            }

            if ($pendingJobs > 10) {
                $status = 'degraded';
                $issues[] = "{$pendingJobs} jobs pending - queue may be backed up";
            }

            return [
                'status' => $status,
                'stuck_jobs' => $stuckJobs,
                'pending_jobs' => $pendingJobs,
                'issues' => $issues,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to check queue health', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to check queue health: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check storage system health
     */
    public function checkStorageHealth(): array
    {
        try {
            $disk = config('media-processing.storage.sermon_disk', 'public');
            $storage = Storage::disk($disk);

            $status = 'healthy';
            $issues = [];

            // Check if storage is accessible
            if (! $storage->exists('.')) {
                $status = 'error';
                $issues[] = 'Storage disk is not accessible';
            }

            // Check available space (if supported)
            try {
                $testFile = 'health-check-'.time().'.txt';
                $storage->put($testFile, 'health check');
                $storage->delete($testFile);
            } catch (\Exception $e) {
                $status = 'degraded';
                $issues[] = 'Storage write test failed: '.$e->getMessage();
            }

            return [
                'status' => $status,
                'disk' => $disk,
                'issues' => $issues,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to check storage health', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to check storage health: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check processing pipeline health
     */
    public function checkProcessingHealth(): array
    {
        try {
            // Check success rate over last 24 hours
            $totalRecent = MediaProcessingLog::where('created_at', '>=', now()->subDay())->count();
            $completedRecent = MediaProcessingLog::where('created_at', '>=', now()->subDay())->completed()->count();
            $failedRecent = MediaProcessingLog::where('created_at', '>=', now()->subDay())->failed()->count();

            $successRate = $totalRecent > 0 ? ($completedRecent / $totalRecent) * 100 : 100;

            $status = 'healthy';
            $issues = [];

            if ($successRate < 80) {
                $status = 'degraded';
                $issues[] = "Low success rate: {$successRate}% in last 24 hours";
            }

            if ($failedRecent > 5) {
                $status = 'degraded';
                $issues[] = "{$failedRecent} processing failures in last 24 hours";
            }

            return [
                'status' => $status,
                'success_rate' => round($successRate, 2),
                'total_recent' => $totalRecent,
                'completed_recent' => $completedRecent,
                'failed_recent' => $failedRecent,
                'issues' => $issues,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to check processing health', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to check processing health: '.$e->getMessage(),
            ];
        }
    }
}
