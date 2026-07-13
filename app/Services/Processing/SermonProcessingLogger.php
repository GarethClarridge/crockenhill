<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

/**
 * Service for centralized logging, performance monitoring, and statistical
 * analysis across the media processing pipeline.
 *
 * Provides a unified API for recording pipeline lifecycle events, API
 * performance metrics, file operations, and health checks, with
 * support for automated log sanitization.
 */
class SermonProcessingLogger
{
    use SanitizesLogData;

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
            $context['error_message'] = $errorMessage;
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
            $context['error_message'] = $errorMessage;
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
            $context['error_message'] = $errorMessage;
        }

        $logLevel = $errorMessage ? 'error' : 'info';
        $sanitizedOperation = $this->sanitizeForLog($operation);
        Log::log($logLevel, "File operation: {$sanitizedOperation}", $this->sanitizeArrayForLog($context));
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
     * Format bytes into human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        return Number::fileSize($bytes, precision: 2);
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
     * Get current execution time since request start.
     */
    private function getExecutionTime(): float
    {
        return microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
    }
}
