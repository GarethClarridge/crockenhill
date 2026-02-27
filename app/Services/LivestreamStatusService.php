<?php

namespace App\Services;

use App\Data\LivestreamProcessingResult;
use App\Data\StandardProcessingResponse;
use App\Models\MediaProcessingLog;

class LivestreamStatusService
{
    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        return StandardProcessingResponse::fromProcessingLog($processingLog);
    }

    public function getProcessingResult(string $processingId): LivestreamProcessingResult
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        return $this->buildProcessingResult($processingLog);
    }

    /**
     * @return array<string, int|float>
     */
    public function getProcessingSummary(): array
    {
        $total = MediaProcessingLog::livestream()->count();
        $pending = MediaProcessingLog::livestream()->where('status', 'pending')->count();
        $processing = MediaProcessingLog::livestream()->where('status', 'processing')->count();
        $completed = MediaProcessingLog::livestream()->where('status', 'completed')->count();
        $failed = MediaProcessingLog::livestream()->where('status', 'failed')->count();

        return [
            'total_processing_requests' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ];
    }

    private function buildProcessingResult(MediaProcessingLog $processingLog): LivestreamProcessingResult
    {
        $segments = $processingLog->segments->map(function ($segment) {
            return new \App\Data\LivestreamSegment(
                startTime: (float) ($segment->start_time ?? 0.0),
                endTime: (float) ($segment->end_time ?? 0.0),
                duration: (float) ($segment->duration ?? 0.0),
                classification: $segment->classification,
                avgRms: (float) ($segment->avg_rms ?? 0.0),
                peakRms: (float) ($segment->peak_rms ?? 0.0),
                isSermonCandidate: $segment->is_sermon_candidate,
                segmentOrder: (int) ($segment->segment_order ?? 0),
                metadata: $segment->metadata,
            );
        })->toArray();

        $segmentsSummary = null;
        if ($processingLog->segments->isNotEmpty()) {
            $segmentsSummary = \App\Models\LivestreamSegment::getSegmentsSummary($processingLog->id);
        }

        return new LivestreamProcessingResult(
            processingId: $processingLog->processing_id,
            status: $processingLog->status->value,
            originalFilename: $processingLog->original_filename,
            fileSize: (int) ($processingLog->file_size ?? 0),
            fileFormat: is_string($processingLog->processing_metadata['format'] ?? null)
                ? $processingLog->processing_metadata['format']
                : 'unknown',
            duration: $processingLog->duration,
            sermonStartTime: $processingLog->sermon_start_time,
            sermonEndTime: $processingLog->sermon_end_time,
            sermonId: $processingLog->sermon_id,
            errorMessage: $processingLog->error_message,
            processingMetadata: $processingLog->processing_metadata,
            startedAt: $processingLog->started_at?->toISOString(),
            completedAt: $processingLog->completed_at?->toISOString(),
            segments: $segments,
            segmentsSummary: $segmentsSummary,
        );
    }
}
