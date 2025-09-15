<?php

namespace App\Services;

use App\Contracts\SermonProcessingServiceInterface;
use Illuminate\Http\UploadedFile;

class SermonProcessingService implements SermonProcessingServiceInterface
{
    public function __construct(
        private SermonAudioProcessingService $audioProcessingService,
        private SermonJobPipelineService $jobPipelineService,
        private SermonStatusManagementService $statusManagementService,
        private SermonValidationService $validationService,
        private SermonProcessingLogger $logger
    ) {}

    /**
     * Process a sermon audio file through the complete automation pipeline
     */
    public function processSermon(UploadedFile $file): ProcessingResult
    {
        return $this->audioProcessingService->processSermon($file);
    }

    /**
     * Process a sermon audio file from livestream with additional metadata
     */
    public function processSermonAudio(UploadedFile $file, array $livestreamMetadata = []): array
    {
        return $this->audioProcessingService->processSermonAudio($file, $livestreamMetadata);
    }

    /**
     * Get the current processing status for a given processing ID
     */
    public function getProcessingStatus(string $processingId): ProcessingStatusResult
    {
        return $this->statusManagementService->getProcessingStatus($processingId);
    }

    /**
     * Get processing statistics and recent activity
     */
    public function getProcessingStatistics(): array
    {
        return $this->statusManagementService->getProcessingStatistics();
    }

    /**
     * Retry failed processing for a given processing ID
     */
    public function retryProcessing(string $processingId): ProcessingResult
    {
        return $this->jobPipelineService->retryProcessing($processingId);
    }

    /**
     * Get failed processing logs that may need manual review
     */
    public function getFailedProcessingLogs(int $limit = 50): array
    {
        return $this->statusManagementService->getFailedProcessingLogs($limit);
    }

    /**
     * Mark processing for manual review
     */
    public function markForManualReview(string $processingId, string $reviewNote = ''): bool
    {
        return $this->statusManagementService->markForManualReview($processingId, $reviewNote);
    }

    /**
     * Apply graceful degradation to failed processing
     */
    public function applyGracefulDegradation(string $processingId): ProcessingResult
    {
        try {
            $processingLog = \App\Models\SermonProcessingLog::where('processing_id', $processingId)->first();

            if (! $processingLog) {
                return ProcessingResult::failure(
                    processingId: $processingId,
                    message: 'Processing log not found',
                    errorCode: 'PROCESSING_LOG_NOT_FOUND'
                );
            }

            if (! $processingLog->sermon_id) {
                return ProcessingResult::failure(
                    processingId: $processingId,
                    message: 'No sermon record found for graceful degradation',
                    errorCode: 'NO_SERMON_RECORD'
                );
            }

            $sermon = \App\Models\Sermon::find($processingLog->sermon_id);
            if (! $sermon) {
                return ProcessingResult::failure(
                    processingId: $processingId,
                    message: 'Sermon record not found',
                    errorCode: 'SERMON_NOT_FOUND'
                );
            }

            // Generate fallback data using validation service
            $fallbackData = $this->validationService->generateFallbackData($sermon, $processingLog);

            // Update sermon with fallback data
            $sermon->update($fallbackData);

            // Mark processing as completed with degradation applied
            $processingLog->update([
                'status' => \App\Enums\ProcessingStatus::COMPLETED,
                'current_step' => 'graceful_degradation_applied',
                'error_message' => null,
            ]);

            $this->logger->logProcessingCompletion($processingId, true, 'Graceful degradation applied');

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Graceful degradation applied successfully',
                statusUrl: route('api.sermons.processing.status', ['processingId' => $processingId])
            );

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to apply graceful degradation', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ProcessingResult::failure(
                processingId: $processingId,
                message: 'Failed to apply graceful degradation: '.$e->getMessage(),
                errorCode: 'DEGRADATION_FAILED'
            );
        }
    }

    /**
     * Get detailed processing logs for troubleshooting
     */
    public function getDetailedProcessingLogs(string $processingId): array
    {
        return $this->statusManagementService->getDetailedProcessingLogs($processingId);
    }

    /**
     * Generate health check information for the processing system
     */
    public function getSystemHealth(): array
    {
        return $this->statusManagementService->getSystemHealth();
    }

    /**
     * Cancel processing for a given processing ID
     */
    public function cancelProcessing(string $processingId): bool
    {
        try {
            $processingLog = \App\Models\SermonProcessingLog::where('processing_id', $processingId)->first();

            if (! $processingLog) {
                return false;
            }

            // If processing is already completed, cannot cancel
            if ($processingLog->status === \App\Enums\ProcessingStatus::COMPLETED) {
                return false;
            }

            // Mark as failed with cancellation message
            $processingLog->update([
                'status' => \App\Enums\ProcessingStatus::FAILED,
                'error_message' => 'Processing cancelled by user',
                'current_step' => 'cancelled',
            ]);

            $this->logger->logProcessingCompletion($processingId, false, 'Processing cancelled by user');

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to cancel processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
