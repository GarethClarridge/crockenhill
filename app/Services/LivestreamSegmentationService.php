<?php

namespace App\Services;

use App\Data\LivestreamProcessingResult;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LivestreamSegmentationService
{
    public function __construct(
        private VideoStorageService $storageService,
        private VideoSegmentationService $segmentationService,
        private ProcessingPipelineBuilder $pipelineBuilder,
        private ProcessingInitiator $processingInitiator
    ) {}

    /**
     * Process video with segmentation (for livestream videos)
     */
    public function processWithSegmentation(UploadedFile $videoFile, ?string $clientFileDate = null): ProcessingResult
    {
        return $this->startProcessing($videoFile, $clientFileDate);
    }

    public function startProcessing(UploadedFile $videoFile, ?string $clientFileDate = null): ProcessingResult
    {
        try {
            Log::info('Starting livestream processing', [
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize(),
                'client_file_date' => $clientFileDate,
            ]);

            if (! $this->storageService->validateStorageSpace($videoFile->getSize())) {
                throw new \Exception('Insufficient storage space for processing');
            }

            $uploadResult = $this->storageService->storeUploadedVideo($videoFile);

            if (! $this->segmentationService->validateVideoFile($uploadResult['full_path'])) {
                throw new \Exception('Invalid video file format');
            }

            $metadata = $this->segmentationService->getVideoMetadata($uploadResult['full_path']);

            // Create processing log via shared initiator with livestream-specific data
            $processingLog = $this->processingInitiator->initiateProcessing(
                $videoFile,
                'livestream',
                $clientFileDate,
                [
                    'source_file_path' => $uploadResult['temp_path'],
                    'file_size' => $uploadResult['file_size'],
                    'duration' => $metadata['duration'],
                    'processing_metadata' => [
                        'upload_time' => now()->toISOString(),
                        'format_details' => $metadata,
                        'mime_type' => $uploadResult['mime_type'],
                        'file_format' => pathinfo($uploadResult['original_filename'], PATHINFO_EXTENSION),
                    ],
                ]
            );

            $this->dispatchProcessingJobs($processingLog);

            Log::info('Livestream processing initiated', [
                'processing_id' => $processingLog->processing_id,
                'log_id' => $processingLog->id,
            ]);

            return ProcessingResult::success(
                processingId: $processingLog->processing_id,
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

    public function retryProcessing(string $processingId): LivestreamProcessingResult
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)
            ->where('processing_type', 'livestream')
            ->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        if (! $processingLog->status->isRetryable()) {
            throw new \Exception('Only failed or cancelled processing can be retried');
        }

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
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)
            ->where('processing_type', 'livestream')
            ->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        if ($processingLog->isComplete()) {
            throw new \Exception('Cannot cancel completed processing');
        }

        $processingLog->markAsCancelled('Processing cancelled by user');

        // Clean up temporary files - collect paths from processing log
        $tempFiles = [];
        if ($processingLog->source_file_path) {
            $tempFiles[] = $processingLog->source_file_path;
        }

        $metadata = $processingLog->processing_metadata ?? [];
        if (isset($metadata['extracted_segment_path'])) {
            $tempFiles[] = $metadata['extracted_segment_path'];
        }
        if (isset($metadata['extracted_audio_path'])) {
            $tempFiles[] = $metadata['extracted_audio_path'];
        }
        if (isset($metadata['temp_video_path'])) {
            $tempFiles[] = $metadata['temp_video_path'];
        }

        if (! empty($tempFiles)) {
            $this->storageService->cleanupTemporaryFiles($tempFiles);
        }

        return true;
    }

    private function dispatchProcessingJobs(MediaProcessingLog $processingLog): void
    {
        $processingId = $processingLog->processing_id;
        $jobs = $this->pipelineBuilder->buildLivestreamPipeline($processingLog);

        Bus::chain($jobs)
            ->catch(fn (\Throwable $e) => $this->handleProcessingFailure($processingId, $e))
            ->onQueue(config('media-processing.queue.name', 'default'))
            ->dispatch();
    }

    public function updateProcessingStatus(string $processingId, string $status): void
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processingLog) {
            $processingLog->update(['status' => $status]);
        }
    }

    private function handleProcessingFailure(string $processingId, \Throwable $e): void
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processingLog) {
            $processingLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            // Clean up temporary files - collect paths from processing log
            $tempFiles = [];
            if ($processingLog->source_file_path) {
                $tempFiles[] = $processingLog->source_file_path;
            }

            $metadata = $processingLog->processing_metadata ?? [];
            if (isset($metadata['extracted_segment_path'])) {
                $tempFiles[] = $metadata['extracted_segment_path'];
            }
            if (isset($metadata['extracted_audio_path'])) {
                $tempFiles[] = $metadata['extracted_audio_path'];
            }
            if (isset($metadata['temp_video_path'])) {
                $tempFiles[] = $metadata['temp_video_path'];
            }

            if (! empty($tempFiles)) {
                $this->storageService->cleanupTemporaryFiles($tempFiles);
            }
        }

        // Send email notification to administrators
        try {
            Mail::to(config('media-processing.email.admin_email'))
                ->queue(new LivestreamProcessingFailed($processingId, $e));
        } catch (\Exception $emailException) {
            Log::warning('Failed to queue livestream processing failure email, continuing', [
                'processing_id' => $processingId,
                'original_error' => $e->getMessage(),
                'email_error' => $emailException->getMessage(),
            ]);
        }
    }

    private function buildProcessingResult(MediaProcessingLog $processingLog): LivestreamProcessingResult
    {
        $segments = $processingLog->segments->map(function (\App\Models\LivestreamSegment $segment) {
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
            status: $processingLog->status->value,
            originalFilename: $processingLog->original_filename,
            fileSize: $processingLog->file_size,
            fileFormat: $processingLog->processing_metadata['file_format'] ?? null,
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
