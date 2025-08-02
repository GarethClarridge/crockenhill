<?php

namespace App\Services;

use App\Data\LivestreamProcessingResult;
use App\Data\LivestreamProcessingStatus;
use App\Models\LivestreamProcessingLog;
use App\Jobs\GenerateRmsLog;
use App\Jobs\AnalyzeSegments;
use App\Jobs\ExtractSermon;
use App\Jobs\SubmitToProcessing;
use App\Jobs\CleanupTemporaryFiles;
use App\Mail\LivestreamProcessingFailed;
use App\Services\ProcessingResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

class LivestreamProcessingService
{
    private VideoStorageService $storageService;
    private VideoSegmentationService $segmentationService;

    public function __construct(
        VideoStorageService $storageService,
        VideoSegmentationService $segmentationService
    ) {
        $this->storageService = $storageService;
        $this->segmentationService = $segmentationService;
    }

    public function processLivestream(UploadedFile $videoFile): ProcessingResult
    {
        return $this->startProcessing($videoFile);
    }

    public function startProcessing(UploadedFile $videoFile): ProcessingResult
    {
        try {
            $processingId = Str::uuid()->toString();
            
            Log::info('Starting livestream processing', [
                'processing_id' => $processingId,
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize()
            ]);

            if (!$this->storageService->validateStorageSpace($videoFile->getSize())) {
                throw new \Exception('Insufficient storage space for processing');
            }

            $uploadResult = $this->storageService->storeUploadedVideo($videoFile);
            
            if (!$this->segmentationService->validateVideoFile($uploadResult['full_path'])) {
                throw new \Exception('Invalid video file format');
            }

            $metadata = $this->segmentationService->getVideoMetadata($uploadResult['full_path']);

            $processingLog = LivestreamProcessingLog::create([
                'processing_id' => $processingId,
                'status' => 'pending',
                'original_filename' => $uploadResult['original_filename'],
                'original_file_path' => $uploadResult['temp_path'],
                'file_size' => $uploadResult['file_size'],
                'file_format' => pathinfo($uploadResult['original_filename'], PATHINFO_EXTENSION),
                'duration' => $metadata['duration'],
                'processing_metadata' => [
                    'upload_time' => now()->toISOString(),
                    'format_details' => $metadata,
                    'mime_type' => $uploadResult['mime_type'],
                ],
            ]);

            $this->dispatchProcessingJobs($processingLog);

            Log::info('Livestream processing initiated', [
                'processing_id' => $processingId,
                'log_id' => $processingLog->id
            ]);

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Livestream processing initiated successfully'
            );

        } catch (\Exception $e) {
            Log::error('Failed to start livestream processing', [
                'error' => $e->getMessage(),
                'original_filename' => $videoFile->getClientOriginalName()
            ]);

            throw $e;
        }
    }

    public function getProcessingStatus(string $processingId): LivestreamProcessingStatus
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (!$processingLog) {
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

        if (!$processingLog) {
            throw new \Exception('Processing record not found');
        }

        return $this->buildProcessingResult($processingLog);
    }

    public function retryProcessing(string $processingId): LivestreamProcessingResult
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if (!$processingLog) {
            throw new \Exception('Processing record not found');
        }

        if (!$processingLog->isFailed()) {
            throw new \Exception('Only failed processing can be retried');
        }

        Log::info('Retrying livestream processing', [
            'processing_id' => $processingId,
            'previous_status' => $processingLog->status
        ]);

        $processingLog->update([
            'status' => 'pending',
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        $processingLog->segments()->delete();

        $this->dispatchProcessingJobs($processingLog);

        return $this->buildProcessingResult($processingLog->fresh());
    }

    public function cancelProcessing(string $processingId): bool
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if (!$processingLog) {
            throw new \Exception('Processing record not found');
        }

        if ($processingLog->isCompleted()) {
            throw new \Exception('Cannot cancel completed processing');
        }

        Log::info('Cancelling livestream processing', [
            'processing_id' => $processingId,
            'current_status' => $processingLog->status
        ]);

        $processingLog->markAsFailed('Processing cancelled by user');

        $this->storageService->cleanupTemporaryFiles($processingId);

        return true;
    }

    private function dispatchProcessingJobs(LivestreamProcessingLog $processingLog): void
    {
        $processingId = $processingLog->processing_id;
        
        // Dispatch job chain for resilient processing as specified in design
        Bus::chain([
            new GenerateRmsLog($processingLog),
            new AnalyzeSegments($processingLog),
            new ExtractSermon($processingLog),
            new SubmitToProcessing($processingLog),
            new CleanupTemporaryFiles($processingLog),
        ])->catch(function (\Throwable $e) use ($processingId) {
            $this->handleProcessingFailure($processingId, $e);
        })->dispatch();
    }
    
    private function handleProcessingFailure(string $processingId, \Throwable $e): void
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();
        
        if ($processingLog) {
            $processingLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            
            // Clean up temporary files
            $this->storageService->cleanupTemporaryFiles($processingId);
        }
        
        // Send email notification to administrators
        Mail::to(config('livestream-processing.admin_email'))
            ->send(new LivestreamProcessingFailed($processingId, $e->getMessage()));
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

    private function getCurrentStep(string $status): string
    {
        return match ($status) {
            'pending' => 'queued',
            'processing' => 'rms_generation',
            'segmentation_complete' => 'segment_analysis',
            'extraction_complete' => 'sermon_extraction',
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
            'processing' => 25,
            'segmenting' => 50,
            'extraction_complete' => 75,
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
                'segments' => $processingLog->segments->map(function($segment) {
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
}