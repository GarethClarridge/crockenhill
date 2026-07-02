<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ProcessingStatus;
use Illuminate\Support\Carbon;

/**
 * @phpstan-type ProcessingReportData array{
 *     processing_id: string,
 *     status?: string|ProcessingStatus,
 *     original_filename: string,
 *     file_size_mb: float,
 *     duration_seconds: float|null,
 *     total_segments?: int,
 *     processing_duration_seconds?: float|int|null,
 *     segment_summary: array{
 *         total_count: int,
 *         song_segments: array{count: int, total_duration: float},
 *         speech_segments: array{count: int, total_duration: float},
 *         sermon_segment: array{start_time: float, end_time: float, duration: float}|null,
 *     },
 *     sermon_processing_status: 'completed'|'not_started',
 *     sermon_id: int|null,
 *     errors: array<int, array{timestamp: string, level: string, message: string}>,
 *     warnings: array<int, array{timestamp: string, level: string, message: string}>,
 *     performance_metrics: array<string, array{execution_time: float, timestamp: string}>,
 *     created_at: Carbon|null,
 *     completed_at: Carbon|null,
 * }
 */
readonly class ProcessingReport
{
    /**
     * @param  ProcessingReportData  $data
     */
    public function __construct(public array $data) {}

    /**
     * @return ProcessingReportData
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT);

        return $json === false ? '{}' : $json;
    }

    public function getStatus(): string
    {
        $status = $this->data['status'] ?? 'unknown';

        // Handle ProcessingStatus enum
        if ($status instanceof ProcessingStatus) {
            return $status->value;
        }

        return (string) $status;
    }

    public function hasErrors(): bool
    {
        return ! empty($this->data['errors']);
    }

    public function hasWarnings(): bool
    {
        return ! empty($this->data['warnings']);
    }

    public function getProcessingDuration(): float|int|null
    {
        return $this->data['processing_duration_seconds'] ?? null;
    }

    public function getSegmentCount(): int
    {
        return $this->data['total_segments'] ?? 0;
    }
}
