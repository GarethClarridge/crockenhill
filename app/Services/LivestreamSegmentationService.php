<?php

namespace App\Services;

use App\Contracts\VideoStorageServiceInterface;
use App\Data\LivestreamProcessingResult;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\GenerateThumbnail;
use App\Jobs\SubmitToProcessing;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
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
    public function processWithSegmentation(UploadedFile $videoFile, ?string $clientFileDate = null): ProcessingResult
    {
        return $this->startProcessing($videoFile, $clientFileDate);
    }

    public function startProcessing(UploadedFile $videoFile, ?string $clientFileDate = null): ProcessingResult
    {
        try {
            $processingId = Str::uuid()->toString();

            Log::info('Starting livestream processing', [
                'processing_id' => $processingId,
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize(),
                'client_file_date' => $clientFileDate,
            ]);

            if (! $this->storageService->validateStorageSpace($videoFile->getSize())) {
                throw new \Exception('Insufficient storage space for processing');
            }

            // Extract date and service from video metadata BEFORE storing (to preserve file timestamps)
            $metadataService = app(\App\Services\MetadataExtractionService::class);
            $extractedDateTime = $metadataService->extractDateFromVideo($videoFile, $clientFileDate);

            // Only use datetime for service detection if we have actual time information (not just date)
            // If the time is midnight (00:00:00), it likely means only the date was extracted
            if ($extractedDateTime->hour !== 0 || $extractedDateTime->minute !== 0 || $extractedDateTime->second !== 0) {
                $extractedService = $metadataService->determineServiceFromTime($extractedDateTime);
            } else {
                // No time info available, fall back to filename-based detection
                $extractedService = $metadataService->determineServiceFromFilename($videoFile->getClientOriginalName());
            }

            $uploadResult = $this->storageService->storeUploadedVideo($videoFile);

            if (! $this->segmentationService->validateVideoFile($uploadResult['full_path'])) {
                throw new \Exception('Invalid video file format');
            }

            $metadata = $this->segmentationService->getVideoMetadata($uploadResult['full_path']);

            Log::info('Extracted metadata from livestream video file', [
                'processing_id' => $processingId,
                'original_filename' => $uploadResult['original_filename'],
                'extracted_date' => $extractedDateTime->toDateString(),
                'extracted_datetime' => $extractedDateTime->toDateTimeString(),
                'extracted_service' => $extractedService->value,
            ]);

            $processingLog = MediaProcessingLog::create([
                'processing_id' => $processingId,
                'processing_type' => 'livestream',
                'status' => 'pending',
                'original_filename' => $uploadResult['original_filename'],
                'source_file_path' => $uploadResult['temp_path'],
                'file_size' => $uploadResult['file_size'],
                'duration' => $metadata['duration'],
                'processing_metadata' => [
                    'upload_time' => now()->toISOString(),
                    'format_details' => $metadata,
                    'mime_type' => $uploadResult['mime_type'],
                    'file_format' => pathinfo($uploadResult['original_filename'], PATHINFO_EXTENSION),
                    'extracted_date' => $extractedDateTime->toDateString(),
                    'extracted_datetime' => $extractedDateTime->toDateTimeString(),
                    'extracted_service' => $extractedService->value,
                    'date_extraction_method' => 'video_metadata_or_filename',
                    'service_extraction_method' => 'datetime_timestamp',
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

            // Extract date from video metadata BEFORE storing (to preserve file timestamps)
            $metadataService = app(\App\Services\MetadataExtractionService::class);
            $extractedDate = $metadataService->extractDateFromVideo($videoFile);

            // Store the video file
            $storedPath = $this->storageService->storeUploadedVideo($videoFile);

            Log::info('Extracted date from direct video file', [
                'processing_id' => $processingId,
                'original_filename' => $videoFile->getClientOriginalName(),
                'extracted_date' => $extractedDate->toDateString(),
            ]);

            // Create processing log
            $processingLog = MediaProcessingLog::create([
                'processing_id' => $processingId,
                'processing_type' => 'livestream',
                'original_filename' => $videoFile->getClientOriginalName(),
                'source_file_path' => $storedPath,
                'status' => 'processing',
                'current_step' => 'initiated',
                'processing_metadata' => [
                    'processing_mode' => 'direct_sermon',
                    'extracted_date' => $extractedDate->toDateString(),
                    'date_extraction_method' => 'video_metadata_or_filename',
                ],
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
                processingId: $processingId,
                message: 'Failed to initiate direct video processing: '.$e->getMessage(),
                errorCode: 'VIDEO_PROCESSING_FAILED'
            );
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

        if (! $processingLog->isFailed()) {
            throw new \Exception('Only failed processing can be retried');
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

        $processingLog->markAsFailed('Processing cancelled by user');
        $this->storageService->cleanupTemporaryFiles([]);

        return true;
    }

    private function dispatchProcessingJobs(MediaProcessingLog $processingLog): void
    {
        $processingId = $processingLog->processing_id;
        $pipelineBuilder = app(ProcessingPipelineBuilder::class);
        $jobs = $pipelineBuilder->buildLivestreamPipeline($processingLog);

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

            // Clean up temporary files
            $this->storageService->cleanupTemporaryFiles([]);
        }

        // Send email notification to administrators
        try {
            Mail::to(config('media-processing.admin_email'))
                ->send(new LivestreamProcessingFailed($processingId, $e));
        } catch (\Exception $emailException) {
            Log::warning('Failed to send livestream processing failure email, continuing', [
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
