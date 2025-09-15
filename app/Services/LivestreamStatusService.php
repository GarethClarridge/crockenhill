<?php

namespace App\Services;

use App\Data\LivestreamProcessingResult;
use App\Data\LivestreamProcessingStatus;
use App\Models\LivestreamProcessingLog;

class LivestreamStatusService
{
    public function getProcessingStatus(string $processingId): LivestreamProcessingStatus
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        $currentStep = $this->getCurrentStep($processingLog->status);
        $progressPercentage = $this->getProgressPercentage($processingLog->status);

        return new LivestreamProcessingStatus(
            processingId: $processingId,
            status: $processingLog->status,
            currentStep: $currentStep,
            progressPercentage: $progressPercentage,
            errorMessage: $processingLog->error_message,
            stepDetails: $this->getStepDetails($processingLog),
            processingStats: $this->getProcessingStats($processingLog),
        );
    }

    public function getProcessingResult(string $processingId): LivestreamProcessingResult
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        return $this->buildProcessingResult($processingLog);
    }

    public function getProcessingSummary(): array
    {
        $total = LivestreamProcessingLog::count();
        $pending = LivestreamProcessingLog::pending()->count();
        $processing = LivestreamProcessingLog::where('status', 'LIKE', '%processing%')
            ->orWhere('status', 'LIKE', '%complete%')
            ->where('status', '!=', 'completed')
            ->count();
        $completed = LivestreamProcessingLog::completed()->count();
        $failed = LivestreamProcessingLog::failed()->count();

        return [
            'total_processing_requests' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ];
    }

    private function getCurrentStep(string $status): string
    {
        return match ($status) {
            'pending' => 'queued',
            'rms_generation' => 'rms_generation',
            'processing' => 'rms_generation',
            'segmentation' => 'segment_analysis',
            'segmenting' => 'segment_analysis',
            'segmentation_complete' => 'segment_analysis',
            'extraction' => 'sermon_extraction',
            'extraction_complete' => 'sermon_extraction',
            'transcription' => 'sermon_processing',
            'sermon_submitted' => 'sermon_processing',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'unknown',
        };
    }

    private function getProgressPercentage(string $status): int
    {
        return match ($status) {
            'pending' => 0,
            'rms_generation' => 15,
            'processing' => 25,
            'segmentation' => 40,
            'segmenting' => 50,
            'segmentation_complete' => 60,
            'extraction' => 70,
            'extraction_complete' => 75,
            'transcription' => 85,
            'sermon_submitted' => 90,
            'completed' => 100,
            'failed' => 0,
            default => 0,
        };
    }

    private function getStepDetails(LivestreamProcessingLog $processingLog): array
    {
        $details = [
            'processing_id' => $processingLog->processing_id,
            'sermon_processing_id' => $processingLog->sermon_processing_id,
            'file_info' => [
                'filename' => $processingLog->original_filename,
                'size' => $processingLog->file_size,
                'format' => $processingLog->file_format,
                'duration' => $processingLog->duration,
            ],
        ];

        if ($processingLog->segments->isNotEmpty()) {
            $details['segmentation'] = [
                'total_segments' => $processingLog->segments->count(),
                'speech_segments' => $processingLog->speechSegments->count(),
                'song_segments' => $processingLog->songSegments->count(),
                'sermon_candidate_found' => $processingLog->sermonCandidateSegment !== null,
                'segments' => $processingLog->segments->map(function ($segment) {
                    return [
                        'segment_order' => $segment->segment_order,
                        'start_time' => $segment->start_time,
                        'end_time' => $segment->end_time,
                        'classification' => $segment->classification,
                        'is_sermon_candidate' => $segment->is_sermon_candidate,
                    ];
                })->toArray(),
            ];
        }

        if ($processingLog->sermon_start_time && $processingLog->sermon_end_time) {
            $details['sermon_extraction'] = [
                'start_time' => $processingLog->sermon_start_time,
                'end_time' => $processingLog->sermon_end_time,
                'duration' => $processingLog->sermon_end_time - $processingLog->sermon_start_time,
            ];
        }

        // Add sermon video path if processing is completed and has sermon
        if ($processingLog->status === 'completed' && $processingLog->sermon_video_path) {
            $details['sermon_video_path'] = $processingLog->sermon_video_path;
        }

        return $details;
    }

    private function getProcessingStats(LivestreamProcessingLog $processingLog): array
    {
        $stats = [];

        if ($processingLog->started_at) {
            $stats['started_at'] = $processingLog->started_at->toISOString();

            if ($processingLog->completed_at) {
                $stats['completed_at'] = $processingLog->completed_at->toISOString();
                $stats['processing_duration'] = $processingLog->started_at->diffInSeconds($processingLog->completed_at);
            } else {
                $stats['processing_duration'] = $processingLog->started_at->diffInSeconds(now());
            }
        }

        return $stats;
    }

    private function buildProcessingResult(LivestreamProcessingLog $processingLog): LivestreamProcessingResult
    {
        $segments = $processingLog->segments->map(function ($segment) {
            return new \App\Data\LivestreamSegment(
                startTime: $segment->start_time,
                endTime: $segment->end_time,
                duration: $segment->duration,
                classification: $segment->classification,
                avgRms: $segment->avg_rms,
                peakRms: $segment->peak_rms,
                isSermonCandidate: $segment->is_sermon_candidate,
                segmentOrder: $segment->segment_order,
                metadata: $segment->metadata,
            );
        })->toArray();

        $segmentsSummary = null;
        if ($processingLog->segments->isNotEmpty()) {
            $segmentsSummary = \App\Models\LivestreamSegment::getSegmentsSummary($processingLog->id);
        }

        return new LivestreamProcessingResult(
            processingId: $processingLog->processing_id,
            status: $processingLog->status,
            originalFilename: $processingLog->original_filename,
            fileSize: $processingLog->file_size,
            fileFormat: $processingLog->file_format,
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