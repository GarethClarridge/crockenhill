<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ProcessingReport;
use App\Enums\LivestreamSegmentClassification;
use App\Enums\ProcessingStatus;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class SermonProcessingLogger
{
    use SanitizesLogData;

    /**
     * Log the start of sermon processing.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logProcessingStart(string $processingId, string $filename, array $metadata = []): void
    {
        $context = [
            'processing_id' => $processingId,
            'filename' => $this->sanitizeForLog($filename),
            'metadata' => $metadata,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'timestamp' => now()->toISOString(),
        ];

        Log::info('Sermon processing started', $this->sanitizeArrayForLog($context));
    }

    /**
     * Log a processing step with performance metrics.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function logProcessingStep(
        string $processingId,
        string $step,
        string $status,
        array $metrics = [],
        ?string $errorMessage = null
    ): void {
        $context = [
            'processing_id' => $processingId,
            'step' => $step,
            'status' => $status,
            'metrics' => array_merge($metrics, [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'execution_time' => $this->getExecutionTime(),
            ]),
            'timestamp' => now()->toISOString(),
        ];

        if ($errorMessage) {
            $context['error_message'] = $this->sanitizeForLog($errorMessage);
        }

        $logLevel = match ($status) {
            'completed' => 'info',
            'failed', 'error' => 'error',
            'degraded' => 'warning',
            default => 'debug',
        };

        $sanitizedStep = $this->sanitizeForLog($step);
        $sanitizedStatus = $this->sanitizeForLog($status);
        Log::log($logLevel, "Processing step: {$sanitizedStep} - {$sanitizedStatus}", $this->sanitizeArrayForLog($context));
    }

    /**
     * Log API call performance and results.
     *
     * @param  array<string, mixed>  $additionalContext
     */
    public function logApiCall(
        string $processingId,
        string $service,
        string $endpoint,
        float $responseTime,
        int $statusCode,
        ?string $errorMessage = null,
        array $additionalContext = []
    ): void {
        $context = array_merge([
            'processing_id' => $processingId,
            'service' => $this->sanitizeForLog($service),
            'endpoint' => $endpoint,
            'response_time_ms' => round($responseTime * 1000, 2),
            'status_code' => $statusCode,
            'timestamp' => now()->toISOString(),
        ], $additionalContext);

        if ($errorMessage) {
            $context['error_message'] = $this->sanitizeForLog($errorMessage);
        }

        $logLevel = $statusCode >= 400 ? 'error' : 'info';
        $sanitizedService = $this->sanitizeForLog($service);
        $sanitizedEndpoint = $this->sanitizeForLog($endpoint);
        Log::log($logLevel, "API call to {$sanitizedService}: {$sanitizedEndpoint}", $this->sanitizeArrayForLog($context));
    }

    /**
     * Log file operations with performance metrics.
     */
    public function logFileOperation(
        string $processingId,
        string $operation,
        string $filePath,
        ?int $fileSize = null,
        ?float $operationTime = null,
        ?string $errorMessage = null
    ): void {
        $context = [
            'processing_id' => $processingId,
            'operation' => $this->sanitizeForLog($operation),
            'file_path' => $this->sanitizeForLog($filePath),
            'timestamp' => now()->toISOString(),
        ];

        if ($fileSize !== null) {
            $context['file_size_bytes'] = $fileSize;
            $context['file_size_human'] = $this->formatBytes($fileSize);
        }

        if ($operationTime !== null) {
            $context['operation_time_ms'] = round($operationTime * 1000, 2);
        }

        if ($errorMessage) {
            $context['error_message'] = $this->sanitizeForLog($errorMessage);
        }

        $logLevel = $errorMessage ? 'error' : 'info';
        $sanitizedOperation = $this->sanitizeForLog($operation);
        Log::log($logLevel, "File operation: {$sanitizedOperation}", $this->sanitizeArrayForLog($context));
    }

    /**
     * Log processing completion with comprehensive statistics.
     *
     * @param  array<string, mixed>  $statistics
     */
    public function logProcessingComplete(
        string $processingId,
        ProcessingStatus $status,
        array $statistics = [],
        ?string $errorMessage = null
    ): void {
        $context = [
            'processing_id' => $processingId,
            'final_status' => $status->value,
            'execution_time' => $this->getExecutionTime(),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'statistics' => $statistics,
            'timestamp' => now()->toISOString(),
        ];

        if ($errorMessage) {
            $context['error_message'] = $this->sanitizeForLog($errorMessage);
        }

        $logLevel = match ($status) {
            ProcessingStatus::Completed => 'info',
            ProcessingStatus::Cancelled => 'info',
            default => 'error',
        };

        $statusLabel = $status->value;
        Log::log($logLevel, "Sermon processing {$statusLabel}", $this->sanitizeArrayForLog($context));
    }

    /**
     * Log error with detailed context for troubleshooting.
     *
     * @param  array<string, mixed>  $additionalContext
     */
    public function logError(
        string $processingId,
        string $step,
        \Throwable $exception,
        array $additionalContext = []
    ): void {
        $context = array_merge([
            'processing_id' => $processingId,
            'step' => $this->sanitizeForLog($step),
            'exception_class' => get_class($exception),
            'exception_message' => $this->sanitizeForLog($exception->getMessage()),
            'exception_code' => $exception->getCode(),
            'exception_file' => self::sanitizeStackTrace($exception->getFile()),
            'exception_line' => $exception->getLine(),
            'stack_trace' => $this->sanitizeStackTrace($exception->getTraceAsString()),
            'memory_usage' => memory_get_usage(true),
            'timestamp' => now()->toISOString(),
        ], $additionalContext);

        $sanitizedStep = $this->sanitizeForLog($step);
        Log::error("Processing error in step: {$sanitizedStep}", $this->sanitizeArrayForLog($context));
    }

    /**
     * Log performance metrics for monitoring.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function logPerformanceMetrics(string $processingId, array $metrics): void
    {
        $context = [
            'processing_id' => $processingId,
            'metrics' => $metrics,
            'timestamp' => now()->toISOString(),
        ];

        Log::info('Performance metrics', $this->sanitizeArrayForLog($context));
    }

    /**
     * Log system health check results.
     *
     * @param  array<string, mixed>  $result
     */
    public function logHealthCheck(string $checkName, array $result): void
    {
        $context = [
            'health_check' => $this->sanitizeForLog($checkName),
            'status' => $result['status'] ?? 'unknown',
            'result' => $result,
            'timestamp' => now()->toISOString(),
        ];

        $logLevel = match ($result['status'] ?? 'unknown') {
            'healthy' => 'info',
            'degraded' => 'warning',
            'error' => 'error',
            default => 'debug',
        };

        $sanitizedCheckName = $this->sanitizeForLog($checkName);
        Log::log($logLevel, "Health check: {$sanitizedCheckName}", $this->sanitizeArrayForLog($context));
    }

    /**
     * Generate processing statistics from logs.
     *
     * @return array<string, mixed>
     */
    public function generateProcessingStatistics(int $days = 7): array
    {
        $startDate = now()->subDays($days);

        $logs = MediaProcessingLog::query()->recent($days)->get();

        $statistics = [
            'period' => [
                'start' => $startDate->toISOString(),
                'end' => now()->toISOString(),
                'days' => $days,
            ],
            'totals' => [
                'processed' => $logs->count(),
                'completed' => $logs->where('status', 'completed')->count(),
                'failed' => $logs->where('status', 'failed')->count(),
                'pending' => $logs->where('status', 'pending')->count(),
                'processing' => $logs->where('status', 'processing')->count(),
            ],
            'success_rate' => 0,
            'average_processing_time' => null,
            'error_patterns' => [],
        ];

        if ($statistics['totals']['processed'] > 0) {
            $statistics['success_rate'] = round(
                ($statistics['totals']['completed'] / $statistics['totals']['processed']) * 100,
                2
            );
        }

        // Calculate average processing time for completed items
        $completedLogs = $logs->where('status', 'completed');
        if ($completedLogs->count() > 0) {
            $totalTime = $completedLogs->sum(function ($log) {
                if ($log->updated_at === null || $log->created_at === null) {
                    return 0;
                }

                return $log->updated_at->diffInSeconds($log->created_at);
            });
            $statistics['average_processing_time'] = round($totalTime / $completedLogs->count(), 2);
        }

        // Analyze error patterns
        $failedLogs = $logs->where('status', 'failed');
        $errorCounts = [];
        foreach ($failedLogs as $log) {
            if ($log->error_message) {
                $errorType = $this->categorizeError($log->error_message);
                $errorCounts[$errorType] = ($errorCounts[$errorType] ?? 0) + 1;
            }
        }
        $statistics['error_patterns'] = $errorCounts;

        Log::info('Processing statistics generated', $this->sanitizeArrayForLog([
            'statistics' => $statistics,
            'timestamp' => now()->toISOString(),
        ]));

        return $statistics;
    }

    /**
     * Format bytes into human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        return Number::fileSize($bytes, precision: 2);
    }

    /**
     * Categorize error messages for pattern analysis.
     */
    private function categorizeError(string $errorMessage): string
    {
        $errorMessage = strtolower($errorMessage);

        if (Str::contains($errorMessage, ['api', 'openai', 'rate limit', 'quota'])) {
            return 'API_ERROR';
        }

        if (Str::contains($errorMessage, ['file', 'storage', 'disk', 'permission'])) {
            return 'STORAGE_ERROR';
        }

        if (Str::contains($errorMessage, ['transcription', 'whisper', 'audio'])) {
            return 'TRANSCRIPTION_ERROR';
        }

        if (Str::contains($errorMessage, ['analysis', 'parsing', 'json'])) {
            return 'ANALYSIS_ERROR';
        }

        if (Str::contains($errorMessage, ['database', 'connection', 'query'])) {
            return 'DATABASE_ERROR';
        }

        if (Str::contains($errorMessage, ['timeout', 'connection', 'network'])) {
            return 'NETWORK_ERROR';
        }

        return 'OTHER_ERROR';
    }

    /**
     * Log a warning for processing.
     *
     * @param  array<string, mixed>  $context
     */
    public function logWarning(string $processingId, string $step, string $message, array $context = []): void
    {
        $sanitizedStep = $this->sanitizeForLog($step);
        Log::warning("Processing warning in step: {$sanitizedStep}", $this->sanitizeArrayForLog([
            'processing_id' => $processingId,
            'step' => $sanitizedStep,
            'warning_message' => $this->sanitizeForLog($message),
            'timestamp' => now()->toISOString(),
            'context' => $context,
        ]));
    }

    /**
     * Generate a processing report for a livestream processing record.
     *
     * @throws \Exception When the processing record is not found.
     */
    public function generateProcessingReport(string $processingId): ProcessingReport
    {
        $processing = MediaProcessingLog::query()
            ->with('segments')
            ->where('processing_id', $processingId)
            ->first();

        if (! $processing) {
            throw new \Exception("Processing record not found for ID: {$processingId}");
        }

        $logs = $this->getLogsForReport($processingId);

        return new ProcessingReport([
            'processing_id' => $processingId,
            'status' => $processing->status->value,
            'original_filename' => $processing->original_filename,
            'file_size_mb' => round(($processing->file_size ?? 0) / 1024 / 1024, 2),
            'duration_seconds' => $processing->duration,
            'total_segments' => $processing->segments->count(),
            'processing_duration_seconds' => $processing->completed_at?->diffInSeconds($processing->created_at),
            'segment_summary' => $this->buildSegmentSummary($processing->segments),
            'sermon_processing_status' => $processing->sermon_id ? 'completed' : 'not_started',
            'sermon_id' => $processing->sermon_id,
            'errors' => $logs->where('level', 'error')->toArray(),
            'warnings' => $logs->where('level', 'warning')->toArray(),
            'performance_metrics' => $this->buildPerformanceMetrics($logs),
            'created_at' => $processing->created_at,
            'completed_at' => $processing->completed_at,
        ]);
    }

    /**
     * Get recent livestream processing activity summary.
     *
     * @return array<string, int|float|null>
     */
    public function getRecentProcessingActivity(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $recentProcessing = MediaProcessingLog::query()
            ->livestream()
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
                ->whereNotNull('created_at')
                ->avg(function (MediaProcessingLog $log): float {
                    $createdAt = $log->created_at;
                    $completedAt = $log->completed_at;

                    if ($createdAt === null || $completedAt === null) {
                        return 0.0;
                    }

                    return $createdAt->diffInMinutes($completedAt);
                }),
        ];
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function getLogsForReport(string $processingId): Collection
    {
        $logFile = storage_path('logs/laravel.log');

        if (! file_exists($logFile)) {
            return collect();
        }

        $logs = collect();
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $logs;
        }

        foreach ($lines as $line) {
            if (str_contains($line, $processingId)) {
                $parsed = $this->parseReportLogLine($line);
                if ($parsed !== null) {
                    $logs->push($parsed);
                }
            }
        }

        return $logs;
    }

    /**
     * @return array<string, string>|null
     */
    private function parseReportLogLine(string $line): ?array
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
     * @param  Collection<int, LivestreamSegment>  $segments
     * @return array<string, mixed>
     */
    private function buildSegmentSummary(Collection $segments): array
    {
        $songSegments = $segments->where('classification', LivestreamSegmentClassification::Song);
        $speechSegments = $segments->where('classification', LivestreamSegmentClassification::Speech);
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
     * @param  Collection<int, array<string, string>>  $logs
     * @return array<string, array<string, float|string>>
     */
    private function buildPerformanceMetrics(Collection $logs): array
    {
        $metrics = [];

        foreach ($logs as $log) {
            if (str_contains($log['message'] ?? '', 'performance metrics')) {
                if (preg_match('/execution_time_seconds":([\d.]+)/', $log['message'], $matches)) {
                    $step = $this->extractStepFromMessage($log['message']);
                    $metrics[$step] = [
                        'execution_time' => (float) $matches[1],
                        'timestamp' => $log['timestamp'],
                    ];
                }
            }
        }

        return $metrics;
    }

    private function extractStepFromMessage(string $message): string
    {
        if (preg_match('/"step":"([^"]+)"/', $message, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    /**
     * Get current execution time since request start.
     */
    private function getExecutionTime(): float
    {
        return microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
    }
}
