<?php

namespace App\Services;

use App\Contracts\VideoStorageServiceInterface;
use App\Data\LivestreamProcessingResult;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AnalyzeSermonTranscriptFromLivestream;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeSermonAudioFromLivestream;
use App\Mail\LivestreamProcessingFailed;
use App\Models\LivestreamProcessingLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class LivestreamSegmentationService
{
    public function __construct(
        private VideoStorageServiceInterface $storageService,
        private VideoSegmentationService $segmentationService
    ) {}

    /**
     * Process video directly without segmentation (for sermon-only videos)
     */
    public function processDirectly(UploadedFile $videoFile): ProcessingResult
    {
        return $this->startProcessingDirectly($videoFile);
    }

    /**
     * Process video with segmentation (for livestream videos)
     */
    public function processWithSegmentation(UploadedFile $videoFile): ProcessingResult
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
                'file_size' => $videoFile->getSize(),
            ]);

            if (! $this->storageService->validateStorageSpace($videoFile->getSize())) {
                throw new \Exception('Insufficient storage space for processing');
            }

            $uploadResult = $this->storageService->storeUploadedVideo($videoFile);

            if (! $this->segmentationService->validateVideoFile($uploadResult['full_path'])) {
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
                'log_id' => $processingLog->id,
            ]);

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Livestream processing initiated successfully'
            );

        } catch (\Exception $e) {
            Log::error('Failed to start livestream processing', [
                'error' => $e->getMessage(),
                'original_filename' => $videoFile->getClientOriginalName(),
            ]);

            throw $e;
        }
    }

    public function startProcessingDirectly(UploadedFile $videoFile): ProcessingResult
    {
        try {
            $processingId = Str::uuid()->toString();

            Log::info('Starting direct video processing', [
                'processing_id' => $processingId,
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize(),
            ]);

            if (! $this->storageService->validateStorageSpace($videoFile->getSize())) {
                throw new \Exception('Insufficient storage space for processing');
            }

            // Store the video file
            $storedPath = $this->storageService->storeVideo($videoFile, $processingId);

            // Create processing log
            $processingLog = LivestreamProcessingLog::create([
                'processing_id' => $processingId,
                'original_filename' => $videoFile->getClientOriginalName(),
                'stored_file_path' => $storedPath,
                'status' => 'processing',
                'processing_type' => 'direct_sermon', // Mark as direct processing
                'current_step' => 'initiated',
            ]);

            // For direct processing, skip segmentation and go straight to sermon processing
            $jobs = [
                new SubmitToProcessing($processingLog),
                new GenerateThumbnail($processingLog),
                new CleanupTemporaryFiles($processingLog),
            ];

            // Chain the jobs for direct processing
            Bus::chain($jobs)->dispatch();

            Log::info('Direct video processing jobs dispatched', [
                'processing_id' => $processingId,
                'job_count' => count($jobs),
            ]);

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Direct video processing initiated successfully'
            );

        } catch (\Exception $e) {
            Log::error('Failed to start direct video processing', [
                'error' => $e->getMessage(),
                'original_filename' => $videoFile->getClientOriginalName(),
            ]);

            return ProcessingResult::failure(
                processingId: $processingId ?? 'unknown',
                message: 'Failed to initiate direct video processing: '.$e->getMessage()
            );
        }
    }

    public function retryProcessing(string $processingId): LivestreamProcessingResult
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        if (! $processingLog->isFailed()) {
            throw new \Exception('Only failed processing can be retried');
        }

        Log::info('Retrying livestream processing', [
            'processing_id' => $processingId,
            'previous_status' => $processingLog->status,
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

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        if ($processingLog->isCompleted()) {
            throw new \Exception('Cannot cancel completed processing');
        }

        Log::info('Cancelling livestream processing', [
            'processing_id' => $processingId,
            'current_status' => $processingLog->status,
        ]);

        $processingLog->markAsFailed('Processing cancelled by user');

        $this->storageService->cleanupTemporaryFiles([]);

        return true;
    }

    private function dispatchProcessingJobs(LivestreamProcessingLog $processingLog): void
    {
        $processingId = $processingLog->processing_id;

        // Dispatch updated job chain for unified livestream processing
        Bus::chain([
            new GenerateRmsLog($processingLog),
            new AnalyzeSegments($processingLog),
            new ExtractSermon($processingLog),
            new SubmitToProcessing($processingLog),
            new TranscribeSermonAudioFromLivestream($processingLog),
            new AnalyzeSermonTranscriptFromLivestream($processingLog),
            new GenerateThumbnail($processingLog),
            new CleanupTemporaryFiles($processingLog),
        ])->catch(function (\Throwable $e) use ($processingId) {
            $this->handleProcessingFailure($processingId, $e);
        })->onQueue(config('livestream-processing.queue.name', 'default'))
            ->dispatch();
    }

    /**
     * Update the processing status for real-time progress tracking
     */
    public function updateProcessingStatus(string $processingId, string $status): void
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if ($processingLog) {
            $processingLog->update(['status' => $status]);

            Log::info('Processing status updated', [
                'processing_id' => $processingId,
                'status' => $status,
            ]);
        }
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
            $this->storageService->cleanupTemporaryFiles([]);
        }

        // Send email notification to administrators
        try {
            Mail::to(config('livestream-processing.admin_email'))
                ->send(new LivestreamProcessingFailed($processingId, $e));
        } catch (\Exception $emailException) {
            Log::warning('Failed to send livestream processing failure email, continuing', [
                'processing_id' => $processingId,
                'original_error' => $e->getMessage(),
                'email_error' => $emailException->getMessage(),
            ]);
        }
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
