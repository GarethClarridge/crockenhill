<?php

declare(strict_types=1);

namespace App\Services\Processing;

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

/**
 * Service for centralized logging, performance monitoring, and statistical
 * analysis across the media processing pipeline.
 *
 * Provides a unified API for recording pipeline lifecycle events, API
 * performance metrics, file operations, and health checks, with
 * support for automated log sanitization.
 *
 * @phpstan-type HealthCheckResult array{
 *     status: string,
 *     message?: string,
 * }
 */
class SermonProcessingLogger
{
    use SanitizesLogData;

    /**
     * Log the start of sermon processing with initial file metadata.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $filename  The original filename of the media
     * @param  array<string, mixed>  $metadata  Initial metadata to record
     */
    public function logProcessingStart(string $processingId, string $filename, array $metadata = []): void
    {
        $context = [
            'processing_id' => $processingId,
            'filename' => $filename,
            'metadata' => $metadata,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'timestamp' => now()->toISOString(),
        ];

        Log::info('Sermon processing started', $this->sanitizeArrayForLog($context));
    }

    /**
     * Log a discrete processing step with performance and memory metrics.
     *
     * Automatically captures current memory usage, peak memory usage,
     * and execution time relative to the request start.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The identifier for the step being logged
     * @param  string  $status  The outcome of the step (completed, failed, degraded)
     * @param  array<string, mixed>  $metrics  Additional performance metrics to record
     * @param  string|null  $errorMessage  Optional error detail for failed or degraded steps
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
     * Log the performance and outcome of an external API call.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $service  The name of the external service (e.g. OpenAI, Pixian)
     * @param  string  $endpoint  The API endpoint or action name
     * @param  float  $responseTime  The round-trip time in seconds
     * @param  int  $statusCode  The HTTP status code returned by the API
     * @param  string|null  $errorMessage  Optional error message for non-200 responses
     * @param  array<string, mixed>  $additionalContext  Extra metadata to include in the log context
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
            'service' => $service,
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
     * Log a filesystem operation with timing and size metadata.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $operation  The type of operation (e.g. upload, move, extract)
     * @param  string  $filePath  The path to the file involved in the operation
     * @param  int|null  $fileSize  The size of the file in bytes
     * @param  float|null  $operationTime  The time taken for the operation in seconds
     * @param  string|null  $errorMessage  Optional error detail if the operation failed
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
            'operation' => $operation,
            'file_path' => $filePath,
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
     * Log the completion or termination of a media processing run.
     *
     * Records final status, total execution time, and peak memory usage
     * for the entire lifecycle.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  ProcessingStatus  $status  The terminal status (Completed, Failed, Cancelled)
     * @param  array<string, mixed>  $statistics  Final processing statistics to record
     * @param  string|null  $errorMessage  Optional error detail for non-successful completions
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
     * Log a critical error with full exception context and stack trace.
     *
     * Automatically redacts sensitive information from stack traces and
     * truncates them to a safe length before logging.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The step where the error occurred
     * @param  \Throwable  $exception  The exception that triggered the error
     * @param  array<string, mixed>  $additionalContext  Extra metadata for troubleshooting
     */
    public function logError(
        string $processingId,
        string $step,
        \Throwable $exception,
        array $additionalContext = []
    ): void {
        $context = array_merge([
            'processing_id' => $processingId,
            'step' => $step,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
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
     * Log arbitrary performance metrics for dashboard monitoring.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  array<string, mixed>  $metrics  Key-value pairs of metrics to record
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
     * Log the result of a system health check.
     *
     * @param  string  $checkName  The identifier for the health check
     * @param  HealthCheckResult  $result  The outcome and diagnostic data for the check
     */
    public function logHealthCheck(string $checkName, array $result): void
    {
        $context = [
            'health_check' => $checkName,
            /** @phpstan-ignore nullCoalesce.offset */
            'status' => $result['status'] ?? 'unknown',
            'result' => $result,
            'timestamp' => now()->toISOString(),
        ];

        /** @phpstan-ignore nullCoalesce.offset */
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
     * Generate high-level processing statistics from recent media logs.
     *
     * @param  int  $days  The number of recent days to include in the analysis
     * @return array{
     *     period: array{start: string|null, end: string|null, days: int},
     *     totals: array{processed: int, completed: int, failed: int, pending: int, processing: int},
     *     success_rate: float|int,
     *     average_processing_time: float|null,
     *     error_patterns: array<string, int>,
     * }
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

                return $log->updated_at->diffInSeconds($log->created_at, true);
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
     * Log a non-terminal warning during media processing.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The step where the warning was triggered
     * @param  string  $message  The warning reason
     * @param  array<string, mixed>  $context  Additional context for the warning
     */
    public function logWarning(string $processingId, string $step, string $message, array $context = []): void
    {
        $sanitizedStep = $this->sanitizeForLog($step);
        Log::warning("Processing warning in step: {$sanitizedStep}", $this->sanitizeArrayForLog([
            'processing_id' => $processingId,
            'step' => $sanitizedStep,
            'warning_message' => $message,
            'timestamp' => now()->toISOString(),
            'context' => $context,
        ]));
    }

    /**
     * Generate a comprehensive processing report for a livestream processing run.
     *
     * Aggregates database metadata, segment summaries, performance metrics,
     * and parsed log entries into a single report object for administrative
     * review and audit trails.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return ProcessingReport The aggregated processing report containing structured metadata and analysis
     *
     * @throws \Exception When the processing record is not found in the database.
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
     * Get a summary of recent livestream processing activity.
     *
     * @param  int  $hours  The number of recent hours to include in the summary
     * @return array{
     *     total_processed: int,
     *     successful: int,
     *     failed: int,
     *     in_progress: int,
     *     average_duration_minutes: float|null,
     * }
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
     * @return Collection<int, array{timestamp: string, level: string, message: string}>
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
     * @return array{timestamp: string, level: string, message: string}|null
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
     * @return array{
     *     total_count: int,
     *     song_segments: array{count: int, total_duration: float},
     *     speech_segments: array{count: int, total_duration: float},
     *     sermon_segment: array{start_time: float, end_time: float, duration: float}|null,
     * }
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
     * @param  Collection<int, array{timestamp: string, level: string, message: string}>  $logs
     * @return array<string, array{execution_time: float, timestamp: string}>
     */
    private function buildPerformanceMetrics(Collection $logs): array
    {
        $metrics = [];

        foreach ($logs as $log) {
            if (str_contains(strtolower($log['message']), 'performance metrics')) {
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
