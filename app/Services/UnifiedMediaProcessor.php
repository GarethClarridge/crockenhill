<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\StandardProcessingResponse;
use App\Services\ProcessingStatusResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class UnifiedMediaProcessor
{
    public function __construct(
        private readonly VideoProcessingService $videoService,
        private readonly SermonProcessingService $sermonService,
        private readonly ProcessingPipelineBuilder $pipelineBuilder
    ) {}

    public function process(string $type, UploadedFile $file): ProcessingResult
    {
        Log::info('Unified media processing started', [
            'type' => $type,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        return match ($type) {
            // Audio: Jump directly to existing sermon processing (reuse existing jobs)
            'audio' => $this->sermonService->processSermon($file),

            // Video: Extract audio then join sermon processing (create new method)
            'video' => $this->processDirectVideo($file),

            // Livestream: Preserve existing system completely (no changes)
            'livestream' => $this->videoService->processWithSegmentation($file),

            default => ProcessingResult::failure(
                processingId: 'invalid-' . \Illuminate\Support\Str::uuid(),
                message: "Unsupported media type: {$type}",
                errorCode: 'UNSUPPORTED_TYPE'
            ),
        };
    }

    public function getStatus(string $processingId): StandardProcessingResponse
    {
        // Try sermon processing first
        if (\App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->convertToStandardResponse($this->sermonService->getProcessingStatus($processingId));
        }

        // Then try livestream processing
        if (\App\Models\LivestreamProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->convertLivestreamToStandardResponse($this->videoService->getProcessingStatus($processingId));
        }

        return StandardProcessingResponse::notFound();
    }

    public function getStatusWithLogs(string $processingId, bool $includeLogs = false, int $logLimit = 20): StandardProcessingResponse
    {
        $baseStatus = $this->getStatus($processingId);

        if (!$baseStatus->found || !$includeLogs) {
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
        // Try both processing types
        if (\App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists()) {
            $result = $this->sermonService->cancelProcessing($processingId);
            return [
                'success' => $result,
                'message' => $result ? 'Sermon processing cancelled successfully' : 'Failed to cancel sermon processing',
            ];
        }

        if (\App\Models\LivestreamProcessingLog::where('processing_id', $processingId)->exists()) {
            $result = $this->videoService->cancelProcessing($processingId);
            return [
                'success' => $result,
                'message' => $result ? 'Livestream processing cancelled successfully' : 'Failed to cancel livestream processing',
            ];
        }

        return ['success' => false, 'message' => 'Processing ID not found'];
    }

    public function retry(string $processingId): ProcessingResult
    {
        if (\App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists()) {
            return $this->sermonService->retryProcessing($processingId);
        }

        if (\App\Models\LivestreamProcessingLog::where('processing_id', $processingId)->exists()) {
            $livestreamResult = $this->videoService->retryProcessing($processingId);

            // Convert LivestreamProcessingResult to ProcessingResult for compatibility
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

        return ProcessingResult::failure(
            processingId: $processingId,
            message: 'Processing ID not found for retry',
            errorCode: 'NOT_FOUND'
        );
    }

    public function canHandle(string $processingId): bool
    {
        return \App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists() ||
               \App\Models\LivestreamProcessingLog::where('processing_id', $processingId)->exists();
    }

    /**
     * Process video by extracting audio and joining existing sermon processing
     * This reuses the proven job chain instead of creating new processing logic
     */
    private function processDirectVideo(UploadedFile $file): ProcessingResult
    {
        try {
            $processingId = \Illuminate\Support\Str::uuid()->toString();

            // Store video file temporarily
            $tempPath = $file->store('temp/video-processing');

            // Create sermon processing log (NOT livestream processing log)
            $processingLog = \App\Models\SermonProcessingLog::create([
                'processing_id' => $processingId,
                'original_filename' => $file->getClientOriginalName(),
                'stored_file_path' => $tempPath,
                'status' => \App\Enums\ProcessingStatus::PENDING,
                'current_step' => 'video_processing_initiated',
            ]);

            // Build and dispatch job chain using the standardized pipeline builder
            $jobs = $this->pipelineBuilder->buildDirectVideoPipeline($processingLog);

            \Illuminate\Support\Facades\Bus::chain($jobs)
                ->catch(function (\Throwable $e) use ($processingLog) {
                    $processingLog->update([
                        'status' => \App\Enums\ProcessingStatus::FAILED,
                        'error_message' => 'Video processing failed: ' . $e->getMessage(),
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
                processingId: 'failed-' . \Illuminate\Support\Str::uuid(),
                message: 'Failed to initiate video processing: ' . $e->getMessage(),
                errorCode: 'VIDEO_PROCESSING_FAILED'
            );
        }
    }

    /**
     * Convert sermon processing status to StandardProcessingResponse
     */
    private function convertToStandardResponse(ProcessingStatusResult $sermonStatus): StandardProcessingResponse
    {
        if (!$sermonStatus->found) {
            return StandardProcessingResponse::notFound();
        }

        $additionalData = [];

        // Include thumbnail information if sermon exists
        if ($sermonStatus->sermonId) {
            $sermon = \App\Models\Sermon::find($sermonStatus->sermonId);
            if ($sermon) {
                $additionalData['thumbnail_generated'] = !empty($sermon->thumbnail_path);
                $additionalData['thumbnail_url'] = $sermon->thumbnail_url;
                $additionalData['thumbnail_generated_at'] = $sermon->thumbnail_generated_at?->toISOString();
            }
        }

        return StandardProcessingResponse::fromProcessingStatus(
            processingId: $sermonStatus->processingId,
            status: $sermonStatus->status->value,
            currentStep: $sermonStatus->currentStep,
            progressPercentage: 0, // ProcessingStatusResult doesn't include progress
            errorMessage: $sermonStatus->errorMessage,
            sermonId: $sermonStatus->sermonId,
            sermonUrl: $sermonStatus->sermonSlug ? "/christ/sermons/{$sermonStatus->sermonSlug}" : null,
            startedAt: $sermonStatus->createdAt,
            updatedAt: $sermonStatus->updatedAt,
            additionalData: $additionalData
        );
    }

    /**
     * Convert livestream processing status to StandardProcessingResponse
     */
    private function convertLivestreamToStandardResponse(\App\Data\LivestreamProcessingStatus $livestreamStatus): StandardProcessingResponse
    {
        return StandardProcessingResponse::fromProcessingStatus(
            processingId: $livestreamStatus->processingId,
            status: $livestreamStatus->status,
            currentStep: $livestreamStatus->currentStep,
            progressPercentage: $livestreamStatus->progressPercentage,
            errorMessage: $livestreamStatus->errorMessage,
            estimatedCompletion: $livestreamStatus->estimatedCompletionTime,
            additionalData: [
                'step_details' => $livestreamStatus->stepDetails,
                'processing_stats' => $livestreamStatus->processingStats,
            ]
        );
    }
}