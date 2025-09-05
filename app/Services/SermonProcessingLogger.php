<?php

namespace App\Services;

use App\Models\SermonProcessingLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SermonProcessingLogger
{
    /**
     * Log the start of sermon processing.
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

        Log::info('Sermon processing started', $context);
    }

    /**
     * Log a processing step with performance metrics.
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
                'execution_time' => microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
            ]),
            'timestamp' => now()->toISOString(),
        ];

        if ($errorMessage) {
            $context['error_message'] = $errorMessage;
        }

        $logLevel = match ($status) {
            'completed' => 'info',
            'failed', 'error' => 'error',
            'degraded' => 'warning',
            default => 'debug',
        };

        Log::log($logLevel, "Processing step: {$step} - {$status}", $context);
    }

    /**
     * Log API call performance and results.
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
            $context['error_message'] = $errorMessage;
        }

        $logLevel = $statusCode >= 400 ? 'error' : 'info';
        Log::log($logLevel, "API call to {$service}: {$endpoint}", $context);
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
            $context['error_message'] = $errorMessage;
        }

        $logLevel = $errorMessage ? 'error' : 'info';
        Log::log($logLevel, "File operation: {$operation}", $context);
    }

    /**
     * Log processing completion with comprehensive statistics.
     */
    public function logProcessingComplete(
        string $processingId,
        string $status,
        array $statistics = [],
        ?string $errorMessage = null
    ): void {
        $context = [
            'processing_id' => $processingId,
            'final_status' => $status,
            'total_execution_time' => microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'statistics' => $statistics,
            'timestamp' => now()->toISOString(),
        ];

        if ($errorMessage) {
            $context['error_message'] = $errorMessage;
        }

        $logLevel = $status === 'completed' ? 'info' : 'error';
        Log::log($logLevel, "Sermon processing {$status}", $context);
    }

    /**
     * Log error with detailed context for troubleshooting.
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
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'stack_trace' => $exception->getTraceAsString(),
            'memory_usage' => memory_get_usage(true),
            'timestamp' => now()->toISOString(),
        ], $additionalContext);

        Log::error("Processing error in step: {$step}", $context);
    }

    /**
     * Log performance metrics for monitoring.
     */
    public function logPerformanceMetrics(string $processingId, array $metrics): void
    {
        $context = [
            'processing_id' => $processingId,
            'metrics' => $metrics,
            'timestamp' => now()->toISOString(),
        ];

        Log::info('Performance metrics', $context);
    }

    /**
     * Log system health check results.
     */
    public function logHealthCheck(string $checkName, array $result): void
    {
        $context = [
            'health_check' => $checkName,
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

        Log::log($logLevel, "Health check: {$checkName}", $context);
    }

    /**
     * Generate processing statistics from logs.
     */
    public function generateProcessingStatistics(int $days = 7): array
    {
        $startDate = now()->subDays($days);

        $logs = SermonProcessingLog::where('created_at', '>=', $startDate)->get();

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

        Log::info('Processing statistics generated', [
            'statistics' => $statistics,
            'timestamp' => now()->toISOString(),
        ]);

        return $statistics;
    }

    /**
     * Format bytes into human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
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
}
