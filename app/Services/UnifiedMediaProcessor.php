<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\StandardProcessingResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class UnifiedMediaProcessor
{
    public function __construct(
        private readonly VideoProcessingService $videoService,
        private readonly SermonProcessingService $sermonService,
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
        private readonly MetadataExtractionService $metadataService
    ) {}

    public function process(string $type, UploadedFile $file, ?string $clientFileDate = null): ProcessingResult
    {
        Log::info('Unified media processing started', [
            'type' => $type,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'client_file_date' => $clientFileDate,
        ]);

        return match ($type) {
            // Audio: Jump directly to existing sermon processing (reuse existing jobs)
            'audio' => $this->sermonService->processSermon($file, $clientFileDate),

            // Video: Extract audio then join sermon processing (create new method)
            'video' => $this->processDirectVideo($file, $clientFileDate),

            // Livestream: Preserve existing system completely (no changes)
            'livestream' => $this->videoService->processWithSegmentation($file, $clientFileDate),

            default => ProcessingResult::failure(
                processingId: 'invalid-'.\Illuminate\Support\Str::uuid(),
                message: "Unsupported media type: {$type}",
                errorCode: 'UNSUPPORTED_TYPE'
            ),
        };
    }

    public function getStatus(string $processingId): StandardProcessingResponse
    {
        $log = \App\Models\MediaProcessingLog::where('processing_id', $processingId)->first();

        if (! $log) {
            return StandardProcessingResponse::notFound();
        }

        return StandardProcessingResponse::fromProcessingLog($log);
    }

    public function getStatusWithLogs(string $processingId, bool $includeLogs = false, int $logLimit = 20): StandardProcessingResponse
    {
        $baseStatus = $this->getStatus($processingId);

        if (! $baseStatus->found || ! $includeLogs) {
            return $baseStatus;
        }

        // Get logs and metrics from the processing log service
        $processingLogService = app(\App\Services\ProcessingLogService::class);
        $logs = $processingLogService->getProcessingLogs($processingId, $logLimit);
        $metrics = $processingLogService->getPerformanceMetrics($processingId);

        return StandardProcessingResponse::withLogs(
            processingId: $baseStatus->processingId,
            status: $baseStatus->status,
            currentStep: $baseStatus->currentStep,
            progressPercentage: $baseStatus->progressPercentage,
            errorMessage: $baseStatus->errorMessage,
            sermonId: $baseStatus->sermonId,
            sermonUrl: $baseStatus->sermonUrl,
            startedAt: $baseStatus->startedAt,
            updatedAt: $baseStatus->updatedAt,
            estimatedCompletion: $baseStatus->estimatedCompletion,
            additionalData: $baseStatus->additionalData,
            logs: $logs,
            metrics: $metrics
        );
    }

    public function cancel(string $processingId): array
    {
        $log = \App\Models\MediaProcessingLog::where('processing_id', $processingId)->first();

        if (! $log) {
            return ['success' => false, 'message' => 'Processing ID not found'];
        }

        $result = match ($log->processing_type) {
            'audio', 'video' => $this->sermonService->cancelProcessing($processingId),
            'livestream' => $this->videoService->cancelProcessing($processingId),
            default => false,
        };

        return [
            'success' => $result,
            'message' => $result ? 'Processing cancelled successfully' : 'Failed to cancel processing',
        ];
    }

    public function retry(string $processingId): ProcessingResult
    {
        $log = \App\Models\MediaProcessingLog::where('processing_id', $processingId)->first();

        if (! $log) {
            return ProcessingResult::failure(
                processingId: $processingId,
                message: 'Processing ID not found for retry',
                errorCode: 'NOT_FOUND'
            );
        }

        return match ($log->processing_type) {
            'audio', 'video' => $this->sermonService->retryProcessing($processingId),
            'livestream' => $this->convertLivestreamRetryResult($this->videoService->retryProcessing($processingId)),
            default => ProcessingResult::failure(
                processingId: $processingId,
                message: "Unknown processing type: {$log->processing_type}",
                errorCode: 'UNKNOWN_TYPE'
            ),
        };
    }

    private function convertLivestreamRetryResult(\App\Data\LivestreamProcessingResult $livestreamResult): ProcessingResult
    {
        if ($livestreamResult->errorMessage) {
            return ProcessingResult::failure(
                processingId: $livestreamResult->processingId,
                message: $livestreamResult->errorMessage,
                errorCode: 'RETRY_FAILED'
            );
        }

        return ProcessingResult::success(
            processingId: $livestreamResult->processingId,
            message: 'Livestream processing retry initiated successfully'
        );
    }

    public function canHandle(string $processingId): bool
    {
        return \App\Models\MediaProcessingLog::where('processing_id', $processingId)->exists();
    }

    /**
     * Process video by extracting audio and joining existing sermon processing
     * This reuses the proven job chain instead of creating new processing logic
     */
    private function processDirectVideo(UploadedFile $file, ?string $clientFileDate = null): ProcessingResult
    {
        try {
            $processingId = \Illuminate\Support\Str::uuid()->toString();

            // Extract date and service from video metadata BEFORE storing (to preserve file timestamps)
            $extractedDateTime = $this->metadataService->extractDateFromVideo($file, $clientFileDate);

            // Only use datetime for service detection if we have actual time information (not just date)
            // If the time is midnight (00:00:00), it likely means only the date was extracted
            if ($extractedDateTime->hour !== 0 || $extractedDateTime->minute !== 0 || $extractedDateTime->second !== 0) {
                $extractedService = $metadataService->determineServiceFromTime($extractedDateTime);
            } else {
                // No time info available, fall back to filename-based detection
                $extractedService = $metadataService->determineServiceFromFilename($file->getClientOriginalName());
            }

            // Store video file temporarily
            $tempPath = $file->store('temp/video-processing');

            Log::info('Extracted metadata from video file', [
                'processing_id' => $processingId,
                'original_filename' => $file->getClientOriginalName(),
                'extracted_date' => $extractedDateTime->toDateString(),
                'extracted_datetime' => $extractedDateTime->toDateTimeString(),
                'extracted_service' => $extractedService->value,
            ]);

            // Create media processing log
            $processingLog = \App\Models\MediaProcessingLog::create([
                'processing_id' => $processingId,
                'processing_type' => 'video',
                'original_filename' => $file->getClientOriginalName(),
                'source_file_path' => $tempPath,
                'status' => \App\Enums\ProcessingStatus::PENDING,
                'current_step' => 'video_processing_initiated',
                'processing_metadata' => [
                    'extracted_date' => $extractedDateTime->toDateString(),
                    'extracted_datetime' => $extractedDateTime->toDateTimeString(),
                    'extracted_service' => $extractedService->value,
                    'date_extraction_method' => 'video_metadata_or_filename',
                    'service_extraction_method' => 'datetime_timestamp',
                ],
            ]);

            // Build and dispatch job chain using the standardized pipeline builder
            $jobs = $this->pipelineBuilder->buildDirectVideoPipeline($processingLog);

            \Illuminate\Support\Facades\Bus::chain($jobs)
                ->catch(function (\Throwable $e) use ($processingLog) {
                    $processingLog->update([
                        'status' => \App\Enums\ProcessingStatus::FAILED,
                        'error_message' => 'Video processing failed: '.$e->getMessage(),
                    ]);
                })
                ->onQueue('video-processing')
                ->dispatch();

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Video processing initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingId])
            );

        } catch (\Exception $e) {
            return ProcessingResult::failure(
                processingId: 'failed-'.\Illuminate\Support\Str::uuid(),
                message: 'Failed to initiate video processing: '.$e->getMessage(),
                errorCode: 'VIDEO_PROCESSING_FAILED'
            );
        }
    }
}
