<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Data\ProcessingLogCollection;
use App\Data\ProcessingLogEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for retrieving and parsing media processing logs.
 *
 * Provides structured visibility into the media processing pipeline's
 * execution, progress, and performance metrics by parsing application-level
 * log entries associated with a specific processing ID.
 */
class ProcessingLogService
{
    /**
     * @param  string  $logFilePath  Optional path to the log file (defaults to storage/logs/laravel.log)
     */
    public function __construct(
        private readonly string $logFilePath = ''
    ) {}

    /**
     * Retrieve a collection of processing logs for a specific ID.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  int  $limit  Maximum number of entries to return (default: 50)
     * @return ProcessingLogCollection The structured collection of log entries
     */
    public function getProcessingLogs(string $processingId, int $limit = 50): ProcessingLogCollection
    {
        $logs = $this->parseLogsFromFile($processingId, $limit);

        return new ProcessingLogCollection(
            entries: $logs,
            totalCount: $logs->count(),
            summary: $this->generateLogSummary($logs)
        );
    }

    /**
     * Retrieve processing logs generated since a specific timestamp.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  Carbon  $since  The cutoff timestamp
     * @return ProcessingLogCollection The structured collection of log entries
     */
    public function getLogsSince(string $processingId, Carbon $since): ProcessingLogCollection
    {
        $logs = $this->parseLogsFromFile($processingId, null, $since);

        return new ProcessingLogCollection(
            entries: $logs,
            totalCount: $logs->count(),
            summary: $this->generateLogSummary($logs)
        );
    }

    /**
     * Retrieve processing logs for a specific pipeline step.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The step identifier to filter by
     * @return ProcessingLogCollection The structured collection of log entries
     */
    public function getLogsByStep(string $processingId, string $step): ProcessingLogCollection
    {
        $allLogs = $this->parseLogsFromFile($processingId);
        $filteredLogs = $allLogs->filter(fn (ProcessingLogEntry $entry) => $entry->step === $step);

        return new ProcessingLogCollection(
            entries: $filteredLogs->values(), // Reset array keys
            totalCount: $filteredLogs->count(),
            summary: $this->generateLogSummary($filteredLogs)
        );
    }

    /**
     * Aggregates performance and resource usage metrics from log entries.
     *
     * Summarizes execution time and memory usage across all steps of a
     * processing run, providing a breakdown of metrics per discrete step.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return array{
     *     total_execution_time: float,
     *     peak_memory_usage: int,
     *     step_metrics: array<string, array{
     *         execution_time: float|null,
     *         memory_usage: int|null,
     *         timestamp: string|null
     *     }>,
     *     total_entries: int,
     *     error_count: int,
     *     warning_count: int,
     * }|null
     */
    public function getPerformanceMetrics(string $processingId): ?array
    {
        $logs = $this->parseLogsFromFile($processingId);

        $stepMetrics = $logs->whereNotNull('executionTime')
            ->mapWithKeys(fn (ProcessingLogEntry $entry) => [
                $entry->step => [
                    'execution_time' => $entry->executionTime,
                    'memory_usage' => $entry->memoryUsage,
                    'timestamp' => $entry->timestamp->toISOString(),
                ],
            ])
            ->all();

        return [
            'total_execution_time' => (float) $logs->sum('executionTime'),
            'peak_memory_usage' => (int) ($logs->max('memoryUsage') ?? 0),
            'step_metrics' => $stepMetrics,
            'total_entries' => $logs->count(),
            'error_count' => $logs->filter(fn ($entry) => $entry->level === 'error')->count(),
            'warning_count' => $logs->filter(fn ($entry) => $entry->level === 'warning')->count(),
        ];
    }

    private function getResolvedLogPath(): string
    {
        return empty($this->logFilePath) ? storage_path('logs/laravel.log') : $this->logFilePath;
    }

    /**
     * @return Collection<int, ProcessingLogEntry>
     */
    private function parseLogsFromFile(string $processingId, ?int $limit = null, ?Carbon $since = null): Collection
    {
        $logPath = $this->getResolvedLogPath();

        if (! file_exists($logPath)) {
            return collect();
        }

        $entries = collect();

        // Stream file reading to avoid memory exhaustion on large log files
        $handle = fopen($logPath, 'r');
        if (! $handle) {
            return collect();
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                // Skip empty lines
                if (empty($line)) {
                    continue;
                }

                // Quick filter: skip lines that don't contain our processing ID
                if (! str_contains($line, $processingId)) {
                    continue;
                }

                $entry = $this->parseLogLine($line, $processingId);
                if (! $entry) {
                    continue;
                }

                if ($since && $entry->timestamp->lt($since)) {
                    continue;
                }

                $entries->push($entry);
            }
        } finally {
            fclose($handle);
        }

        // Sort by timestamp descending (newest first)
        $entries = $entries->sortByDesc(fn (ProcessingLogEntry $entry) => $entry->timestamp->timestamp);

        if ($limit) {
            $entries = $entries->take($limit);
        }

        return $entries->values();
    }

    private function parseLogLine(string $line, string $processingId): ?ProcessingLogEntry
    {
        // Parse Laravel log format: [YYYY-MM-DD HH:MM:SS] environment.LEVEL: Message {context}
        $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*\.(\w+):\s*(.+?)(\{.*\})?$/';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        $timestamp = Carbon::createFromFormat('Y-m-d H:i:s', $matches[1]);
        if (! $timestamp instanceof Carbon) {
            return null;
        }

        $level = strtolower($matches[2]);
        $message = trim($matches[3]);
        $contextJson = $matches[4] ?? '{}';

        // Parse context JSON
        $context = [];
        if ($contextJson !== '{}') {
            try {
                $context = json_decode($contextJson, true, 512, JSON_THROW_ON_ERROR) ?: [];
            } catch (\JsonException) {
                // If JSON parsing fails, skip this log entry entirely
                return null;
            }
        }

        // Extract step from message or context
        $step = $this->extractStep($message, $context);

        // Extract performance metrics from context
        $executionTime = $this->extractExecutionTime($context);
        $memoryUsage = $this->extractMemoryUsage($context);
        $errorMessage = $context['error_message'] ?? ($level === 'error' ? $message : null);

        return new ProcessingLogEntry(
            step: $step,
            level: $level,
            message: $message,
            timestamp: $timestamp,
            metrics: $this->extractMetrics($context),
            errorMessage: $errorMessage,
            context: $context,
            executionTime: $executionTime,
            memoryUsage: $memoryUsage
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function extractStep(string $message, array $context): string
    {
        // Check context first
        if (isset($context['step'])) {
            return $context['step'];
        }

        // Extract step from common message patterns
        if (preg_match('/Processing step:\s*([^-\s]+)/', $message, $matches)) {
            return $matches[1];
        }

        if (preg_match('/step:\s*([^-\s]+)/', $message, $matches)) {
            return $matches[1];
        }

        // Extract step from test patterns like "Step 1", "Step 2"
        if (preg_match('/Step\s+(\d+)/', $message, $matches)) {
            return 'step_'.$matches[1];
        }

        // Extract step from numbered log patterns
        if (preg_match('/^([^{]+)\s+\{/', $message, $matches)) {
            $stepText = trim($matches[1]);
            if (preg_match('/Step\s+(\d+)/', $stepText, $stepMatches)) {
                return 'step_'.$stepMatches[1];
            }
        }

        // Extract from common processing messages
        if (str_contains($message, 'processing started')) {
            return 'initialization';
        }

        if (str_contains($message, 'processing completed') || str_contains($message, 'processing failed')) {
            return 'completion';
        }

        if (str_contains($message, 'API call')) {
            return 'api_call';
        }

        if (str_contains($message, 'File operation')) {
            return 'file_operation';
        }

        if (str_contains($message, 'transcription') || str_contains($message, 'Whisper')) {
            return 'transcription';
        }

        if (str_contains($message, 'analysis') || str_contains($message, 'AI')) {
            return 'analysis';
        }

        if (str_contains($message, 'segmentation') || str_contains($message, 'RMS')) {
            return 'segmentation';
        }

        if (str_contains($message, 'extraction') || str_contains($message, 'FFmpeg')) {
            return 'extraction';
        }

        return 'general';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function extractExecutionTime(array $context): ?float
    {
        // Check various execution time fields
        $timeFields = [
            'execution_time',
            'execution_time_ms',
            'execution_time_seconds',
            'response_time_ms',
            'operation_time_ms',
        ];

        foreach ($timeFields as $field) {
            if (isset($context[$field])) {
                $time = (float) $context[$field];
                // Convert milliseconds to seconds if needed
                if (str_contains($field, '_ms') && $time > 100) {
                    return $time / 1000;
                }

                return $time;
            }
        }

        // Check metrics array
        if (isset($context['metrics']['execution_time'])) {
            return (float) $context['metrics']['execution_time'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function extractMemoryUsage(array $context): ?int
    {
        $memoryFields = [
            'memory_usage',
            'memory_usage_mb',
            'peak_memory',
            'peak_memory_usage',
        ];

        foreach ($memoryFields as $field) {
            if (isset($context[$field])) {
                $memory = (int) $context[$field];
                // Convert MB to bytes if needed
                if (str_contains($field, '_mb') && $memory < 1000) {
                    return $memory * 1024 * 1024;
                }

                return $memory;
            }
        }

        // Check metrics array
        if (isset($context['metrics']['memory_usage'])) {
            return (int) $context['metrics']['memory_usage'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function extractMetrics(array $context): ?array
    {
        $metrics = [];

        // File size information
        if (isset($context['file_size_bytes'])) {
            $metrics['file_size'] = [
                'bytes' => $context['file_size_bytes'],
                'human' => $context['file_size_human'] ?? null,
            ];
        }

        // API call metrics
        if (isset($context['status_code'])) {
            $metrics['api'] = [
                'status_code' => $context['status_code'],
                'service' => $context['service'] ?? null,
                'endpoint' => $context['endpoint'] ?? null,
            ];
        }

        // Processing-specific metrics
        if (isset($context['metrics']) && is_array($context['metrics'])) {
            $metrics = array_merge($metrics, $context['metrics']);
        }

        return empty($metrics) ? null : $metrics;
    }

    /**
     * @param  Collection<int, ProcessingLogEntry>  $logs
     * @return array<string, mixed>
     */
    private function generateLogSummary(Collection $logs): array
    {
        $summary = [
            'total_entries' => $logs->count(),
            'levels' => [],
            'steps' => [],
            'timespan' => null,
            'performance' => null,
        ];

        if ($logs->isEmpty()) {
            return $summary;
        }

        // Count by level
        $summary['levels'] = $logs->countBy('level')->toArray();

        // Count by step
        $summary['steps'] = $logs->countBy('step')->toArray();

        // Calculate timespan
        $timestamps = $logs->pluck('timestamp')->sort();
        if ($timestamps->count() > 1) {
            $summary['timespan'] = [
                'start' => $timestamps->first()->toISOString(),
                'end' => $timestamps->last()->toISOString(),
                'duration_seconds' => $timestamps->first()->diffInSeconds($timestamps->last()),
            ];
        }

        // Performance summary
        $executionTimes = $logs->pluck('executionTime')->filter();
        if ($executionTimes->isNotEmpty()) {
            $summary['performance'] = [
                'total_execution_time' => $executionTimes->sum(),
                'average_step_time' => $executionTimes->avg(),
                'max_step_time' => $executionTimes->max(),
            ];
        }

        return $summary;
    }
}
