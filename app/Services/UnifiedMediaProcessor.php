<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\StandardProcessingResponse;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UnifiedMediaProcessor
{
    public function __construct(
        private readonly SermonAudioProcessingService $audioProcessingService,
        private readonly SermonJobPipelineService $jobPipelineService,
        private readonly SermonProcessingService $sermonService,
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
        private readonly ProcessingLogService $processingLogService,
        private readonly ProcessingInitiator $processingInitiator,
        private readonly LivestreamSegmentationService $livestreamService
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
            'audio' => $this->audioProcessingService->processSermon($file, $clientFileDate),
            'video' => $this->processDirectVideo($file, $clientFileDate),
            'livestream' => $this->livestreamService->startProcessing($file, $clientFileDate),
            default => ProcessingResult::failure(
                processingId: 'invalid-'.Str::uuid(),
                message: "Unsupported media type: {$type}",
                errorCode: 'UNSUPPORTED_TYPE'
            ),
        };
    }

    public function getStatus(string $processingId): StandardProcessingResponse
    {
        $log = $this->findProcessingLog($processingId);

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

        $logs = $this->processingLogService->getProcessingLogs($processingId, $logLimit);
        $metrics = $this->processingLogService->getPerformanceMetrics($processingId);

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

    /**
     * @return array{success: bool, message: string}
     */
    public function cancel(string $processingId): array
    {
        $log = $this->findProcessingLog($processingId);

        if (! $log) {
            return ['success' => false, 'message' => 'Processing ID not found'];
        }

        $result = match ($log->processing_type) {
            'audio', 'video' => $this->sermonService->cancelProcessing($processingId),
            'livestream' => $this->livestreamService->cancelProcessing($processingId),
            default => false,
        };

        return [
            'success' => $result,
            'message' => $result ? 'Processing cancelled successfully' : 'Failed to cancel processing',
        ];
    }

    public function retry(string $processingId): ProcessingResult
    {
        $log = $this->findProcessingLog($processingId);

        if (! $log) {
            return ProcessingResult::failure(
                processingId: $processingId,
                message: 'Processing ID not found for retry',
                errorCode: 'NOT_FOUND'
            );
        }

        return match ($log->processing_type) {
            'audio', 'video' => $this->jobPipelineService->retryProcessing($processingId),
            'livestream' => $this->convertLivestreamRetryResult($this->livestreamService->retryProcessing($processingId)),
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
        return $this->processingLogQuery()
            ->where('processing_id', $processingId)
            ->exists();
    }

    private function findProcessingLog(string $processingId): ?MediaProcessingLog
    {
        return $this->processingLogQuery()
            ->where('processing_id', $processingId)
            ->first();
    }

    /**
     * @return Builder<MediaProcessingLog>
     */
    private function processingLogQuery(): Builder
    {
        $query = MediaProcessingLog::query();
        $user = Auth::user();

        if ($user instanceof User) {
            $query->visibleTo($user);
        }

        return $query;
    }

    /**
     * Process video by extracting audio and joining existing sermon processing.
     * Uses ProcessingInitiator for shared metadata extraction and log creation.
     */
    private function processDirectVideo(UploadedFile $file, ?string $clientFileDate = null): ProcessingResult
    {
        try {
            // Store video file temporarily before processing (preserves file timestamps for metadata extraction)
            $tempPath = $file->store('temp/video-processing');

            // Create processing log via shared initiator
            $processingLog = $this->processingInitiator->initiateProcessing(
                $file,
                'video',
                $clientFileDate,
                ['source_file_path' => $tempPath]
            );

            // Build and dispatch job chain using the standardized pipeline builder
            $jobs = $this->pipelineBuilder->buildDirectVideoPipeline($processingLog);

            Bus::chain($jobs)
                ->catch(function (\Throwable $e) use ($processingLog) {
                    $processingLog->update([
                        'status' => \App\Enums\ProcessingStatus::FAILED,
                        'error_message' => 'Video processing failed: '.$e->getMessage(),
                    ]);
                })
                ->onQueue((string) config('media-processing.queues.video', config('media-processing.types.video.queue', 'video-processing')))
                ->dispatch();

            return ProcessingResult::success(
                processingId: $processingLog->processing_id,
                message: 'Video processing initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
            );

        } catch (\Exception $e) {
            return ProcessingResult::failure(
                processingId: 'failed-'.Str::uuid(),
                message: 'Failed to initiate video processing: '.$e->getMessage(),
                errorCode: 'VIDEO_PROCESSING_FAILED'
            );
        }
    }
}
