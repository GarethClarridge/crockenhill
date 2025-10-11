<?php

namespace App\Services;

use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LivestreamMonitoringService
{
    public function getProcessingMetrics(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $cacheKey = "livestream_metrics_{$hours}h_".now()->format('H');

        return Cache::remember($cacheKey, 300, function () use ($since) { // Cache for 5 minutes
            $processings = MediaProcessingLog::livestream()
                ->where('created_at', '>=', $since)
                ->with('segments')
                ->get();

            $completed = $processings->where('status', 'completed');
            $failed = $processings->where('status', 'failed');
            $processing = $processings->where('status', 'processing');

            return [
                'summary' => [
                    'total_processed' => $processings->count(),
                    'successful' => $completed->count(),
                    'failed' => $failed->count(),
                    'in_progress' => $processing->count(),
                    'success_rate' => $processings->count() > 0
                        ? round(($completed->count() / $processings->count()) * 100, 2)
                        : 0,
                ],
                'processing_times' => $this->calculateProcessingTimes($completed),
                'failure_analysis' => $this->analyzeFailures($failed),
                'segmentation_stats' => $this->analyzeSegmentation($completed),
                'storage_usage' => $this->calculateStorageUsage($completed),
                'manual_review_rate' => $this->calculateManualReviewRate($processings),
            ];
        });
    }

    private function calculateProcessingTimes($completed): array
    {
        if ($completed->isEmpty()) {
            return [
                'average_duration_minutes' => 0,
                'median_duration_minutes' => 0,
                'min_duration_minutes' => 0,
                'max_duration_minutes' => 0,
            ];
        }

        $durations = $completed->map(function ($log) {
            if ($log->completed_at && $log->created_at) {
                return $log->completed_at->diffInMinutes($log->created_at);
            }

            return 0;
        })->filter();

        $sorted = $durations->sort()->values();
        $count = $sorted->count();

        return [
            'average_duration_minutes' => round($durations->avg(), 2),
            'median_duration_minutes' => $count > 0
                ? ($count % 2 === 0
                    ? ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2
                    : $sorted[intval($count / 2)])
                : 0,
            'min_duration_minutes' => $durations->min() ?? 0,
            'max_duration_minutes' => $durations->max() ?? 0,
        ];
    }

    private function analyzeFailures($failed): array
    {
        $failureTypes = [];
        $failureSteps = [];

        foreach ($failed as $failure) {
            $errorMessage = strtolower($failure->error_message ?? '');

            // Categorize failures
            if (str_contains($errorMessage, 'disk') || str_contains($errorMessage, 'space')) {
                $failureTypes['storage_issues'] = ($failureTypes['storage_issues'] ?? 0) + 1;
            } elseif (str_contains($errorMessage, 'ffmpeg') || str_contains($errorMessage, 'codec')) {
                $failureTypes['video_processing'] = ($failureTypes['video_processing'] ?? 0) + 1;
            } elseif (str_contains($errorMessage, 'permission') || str_contains($errorMessage, 'access')) {
                $failureTypes['permission_errors'] = ($failureTypes['permission_errors'] ?? 0) + 1;
            } elseif (str_contains($errorMessage, 'timeout')) {
                $failureTypes['timeouts'] = ($failureTypes['timeouts'] ?? 0) + 1;
            } else {
                $failureTypes['other'] = ($failureTypes['other'] ?? 0) + 1;
            }
        }

        return [
            'total_failures' => $failed->count(),
            'failure_types' => $failureTypes,
            'recent_failures' => $failed->take(5)->map(function ($failure) {
                return [
                    'processing_id' => $failure->processing_id,
                    'error_message' => $failure->error_message,
                    'created_at' => $failure->created_at->toISOString(),
                ];
            })->toArray(),
        ];
    }

    private function analyzeSegmentation($completed): array
    {
        $segmentStats = [];
        $sermonIdentified = 0;
        $totalSegments = 0;

        foreach ($completed as $processing) {
            $segments = $processing->segments;
            $totalSegments += $segments->count();

            if ($segments->where('is_sermon_segment', true)->isNotEmpty()) {
                $sermonIdentified++;
            }

            $songSegments = $segments->where('classification', 'song')->count();
            $speechSegments = $segments->where('classification', 'speech')->count();

            $segmentStats[] = [
                'total' => $segments->count(),
                'song' => $songSegments,
                'speech' => $speechSegments,
            ];
        }

        $avgSegments = $completed->count() > 0 ? $totalSegments / $completed->count() : 0;
        $sermonIdentificationRate = $completed->count() > 0
            ? ($sermonIdentified / $completed->count()) * 100
            : 0;

        return [
            'average_segments_per_processing' => round($avgSegments, 2),
            'sermon_identification_rate' => round($sermonIdentificationRate, 2),
            'total_segments_processed' => $totalSegments,
        ];
    }

    private function calculateStorageUsage($completed): array
    {
        $totalSize = 0;
        $videoFilesStored = 0;

        foreach ($completed as $processing) {
            $totalSize += $processing->file_size ?? 0;

            if ($processing->sermon_id) {
                $videoFilesStored++;
            }
        }

        return [
            'total_input_size_gb' => round($totalSize / (1024 * 1024 * 1024), 2),
            'video_files_stored' => $videoFilesStored,
            'average_file_size_mb' => $completed->count() > 0
                ? round(($totalSize / $completed->count()) / (1024 * 1024), 2)
                : 0,
        ];
    }

    private function calculateManualReviewRate($processings): array
    {
        $manualReviewRequired = $processings->where('status', 'manual_review_required')->count();
        $total = $processings->count();

        return [
            'manual_review_required' => $manualReviewRequired,
            'manual_review_rate' => $total > 0 ? round(($manualReviewRequired / $total) * 100, 2) : 0,
        ];
    }

    public function getSystemHealth(): array
    {
        return [
            'queue_health' => $this->getQueueHealth(),
            'storage_health' => $this->getStorageHealth(),
            'processing_health' => $this->getProcessingHealth(),
        ];
    }

    private function getQueueHealth(): array
    {
        try {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            $status = 'healthy';
            if ($pendingJobs > 100) {
                $status = 'warning';
            }
            if ($pendingJobs > 500 || $failedJobs > 20) {
                $status = 'critical';
            }

            return [
                'status' => $status,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getStorageHealth(): array
    {
        try {
            $livestreamsPath = storage_path('app/livestreams');
            $tempPath = storage_path('app/temp');

            $livestreamsSize = $this->getDirectorySize($livestreamsPath);
            $tempSize = $this->getDirectorySize($tempPath);
            $freeSpace = disk_free_space(storage_path());

            $status = 'healthy';
            $freeSpaceGB = $freeSpace / (1024 * 1024 * 1024);

            if ($freeSpaceGB < 10) {
                $status = 'critical';
            } elseif ($freeSpaceGB < 50) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'free_space_gb' => round($freeSpaceGB, 2),
                'livestreams_size_gb' => round($livestreamsSize / (1024 * 1024 * 1024), 2),
                'temp_size_gb' => round($tempSize / (1024 * 1024 * 1024), 2),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getDirectorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function getProcessingHealth(): array
    {
        $stuckJobs = MediaProcessingLog::livestream()
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subHours(2))
            ->count();

        $recentFailures = MediaProcessingLog::livestream()
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $status = 'healthy';
        if ($stuckJobs > 0 || $recentFailures > 3) {
            $status = 'warning';
        }
        if ($stuckJobs > 2 || $recentFailures > 10) {
            $status = 'critical';
        }

        return [
            'status' => $status,
            'stuck_jobs' => $stuckJobs,
            'recent_failures' => $recentFailures,
        ];
    }

    public function getPerformanceTrends(int $days = 7): array
    {
        $dailyStats = [];

        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i);
            $startOfDay = $date->copy()->startOfDay();
            $endOfDay = $date->copy()->endOfDay();

            $processings = MediaProcessingLog::livestream()
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->get();

            $completed = $processings->where('status', 'completed');
            $avgDuration = $completed->isNotEmpty()
                ? $completed->avg(function ($log) {
                    return $log->completed_at?->diffInMinutes($log->created_at) ?? 0;
                })
                : 0;

            $dailyStats[] = [
                'date' => $date->format('Y-m-d'),
                'total_processed' => $processings->count(),
                'successful' => $completed->count(),
                'failed' => $processings->where('status', 'failed')->count(),
                'average_duration_minutes' => round($avgDuration, 2),
            ];
        }

        return array_reverse($dailyStats); // Most recent first
    }

    public function generateAlerts(): array
    {
        $alerts = [];
        $health = $this->getSystemHealth();

        if ($health['queue_health']['status'] === 'critical') {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'queue_health',
                'message' => 'Queue system is in critical state',
                'details' => $health['queue_health'],
            ];
        }

        if ($health['storage_health']['status'] === 'critical') {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'storage_health',
                'message' => 'Storage space is critically low',
                'details' => $health['storage_health'],
            ];
        }

        if ($health['processing_health']['status'] === 'critical') {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'processing_health',
                'message' => 'Processing pipeline has critical issues',
                'details' => $health['processing_health'],
            ];
        }

        return $alerts;
    }
}
