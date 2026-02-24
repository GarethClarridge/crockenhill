<?php

namespace App\Services;

use App\Models\MediaProcessingLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LivestreamProcessingLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function logProcessingStep(string $processingId, string $step, array $context = []): void
    {
        Log::info("Livestream processing step: {$step}", [
            'processing_id' => $processingId,
            'step' => $step,
            'timestamp' => now(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'context' => $context,
        ]);
    }

    public function logError(string $processingId, string $step, \Throwable $exception): void
    {
        Log::error("Livestream processing error in step: {$step}", [
            'processing_id' => $processingId,
            'step' => $step,
            'error_message' => $exception->getMessage(),
            'stack_trace' => $exception->getTraceAsString(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'timestamp' => now(),
            'memory_usage' => memory_get_usage(true),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logWarning(string $processingId, string $step, string $message, array $context = []): void
    {
        Log::warning("Livestream processing warning in step: {$step}", [
            'processing_id' => $processingId,
            'step' => $step,
            'warning_message' => $message,
            'timestamp' => now(),
            'context' => $context,
        ]);
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function logPerformanceMetrics(string $processingId, string $step, float $executionTime, array $metrics = []): void
    {
        Log::info("Livestream processing performance metrics: {$step}", [
            'processing_id' => $processingId,
            'step' => $step,
            'execution_time_seconds' => round($executionTime, 3),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'timestamp' => now(),
            'metrics' => $metrics,
        ]);
    }

    public function generateProcessingReport(string $processingId): ProcessingReport
    {
        $processing = MediaProcessingLog::with('segments')->where('processing_id', $processingId)->first();

        if (! $processing) {
            throw new \Exception("Processing record not found for ID: {$processingId}");
        }

        $logs = $this->getProcessingLogs($processingId);

        return new ProcessingReport([
            'processing_id' => $processingId,
            'status' => $processing->status->value,
            'original_filename' => $processing->original_filename,
            'file_size_mb' => round($processing->file_size / 1024 / 1024, 2),
            'duration_seconds' => $processing->duration,
            'total_segments' => $processing->segments->count(),
            'processing_duration_seconds' => $processing->completed_at?->diffInSeconds($processing->created_at),
            'segment_summary' => $this->generateSegmentSummary($processing->segments),
            'sermon_processing_status' => $processing->sermon_id ? 'completed' : 'not_started',
            'sermon_id' => $processing->sermon_id,
            'errors' => $logs->where('level', 'error')->toArray(),
            'warnings' => $logs->where('level', 'warning')->toArray(),
            'performance_metrics' => $this->extractPerformanceMetrics($logs),
            'created_at' => $processing->created_at,
            'completed_at' => $processing->completed_at,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function getProcessingLogs(string $processingId): Collection
    {
        $logFile = storage_path('logs/laravel.log');

        if (! file_exists($logFile)) {
            return collect();
        }

        $logs = collect();
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            if (str_contains($line, $processingId)) {
                $logs->push($this->parseLogLine($line));
            }
        }

        return $logs->filter();
    }

    /**
     * @return array<string, string>|null
     */
    private function parseLogLine(string $line): ?array
    {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*\.(\w+):/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => strtolower($matches[2]),
                'message' => $line,
            ];
        }

        return null;
    }

    /**
     * @param mixed $segments
     * @return array<string, mixed>
     */
    private function generateSegmentSummary($segments): array
    {
        $songSegments = $segments->where('classification', 'song');
        $speechSegments = $segments->where('classification', 'speech');
        $sermonSegment = $segments->where('is_sermon_candidate', true)->first();

        return [
            'total_count' => $segments->count(),
            'song_segments' => [
                'count' => $songSegments->count(),
                'total_duration' => round($songSegments->sum('duration'), 2),
            ],
            'speech_segments' => [
                'count' => $speechSegments->count(),
                'total_duration' => round($speechSegments->sum('duration'), 2),
            ],
            'sermon_segment' => $sermonSegment ? [
                'start_time' => $sermonSegment->start_time,
                'end_time' => $sermonSegment->end_time,
                'duration' => $sermonSegment->duration,
            ] : null,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $logs
     * @return array<string, array<string, float|string>>
     */
    private function extractPerformanceMetrics(Collection $logs): array
    {
        $performanceLogs = $logs->filter(function ($log) {
            return str_contains($log['message'] ?? '', 'performance metrics');
        });

        $metrics = [];
        foreach ($performanceLogs as $log) {
            if (preg_match('/execution_time_seconds":([\d.]+)/', $log['message'], $matches)) {
                $step = $this->extractStepFromLog($log['message']);
                $metrics[$step] = [
                    'execution_time' => (float) $matches[1],
                    'timestamp' => $log['timestamp'],
                ];
            }
        }

        return $metrics;
    }

    private function extractStepFromLog(string $message): string
    {
        if (preg_match('/"step":"([^"]+)"/', $message, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function logProcessingCompletion(string $processingId, bool $success, array $summary = []): void
    {
        $level = $success ? 'info' : 'error';
        $message = $success ? 'Livestream processing completed successfully' : 'Livestream processing failed';

        Log::log($level, $message, [
            'processing_id' => $processingId,
            'success' => $success,
            'timestamp' => now(),
            'summary' => $summary,
        ]);
    }

    /**
     * @return array<string, int|float|null>
     */
    public function getRecentProcessingActivity(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $recentProcessing = MediaProcessingLog::livestream()
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'total_processed' => $recentProcessing->count(),
            'successful' => $recentProcessing->where('status', 'completed')->count(),
            'failed' => $recentProcessing->where('status', 'failed')->count(),
            'in_progress' => $recentProcessing->where('status', 'processing')->count(),
            'average_duration_minutes' => $recentProcessing
                ->whereNotNull('completed_at')
                ->avg(function ($log) {
                    return $log->created_at->diffInMinutes($log->completed_at);
                }),
        ];
    }
}
