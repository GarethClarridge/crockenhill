<?php

namespace App\Services;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Support\Facades\Log;

class SermonProcessingService
{
    public function __construct(
        private SermonValidationService $validationService,
        private SermonProcessingLogger $logger
    ) {}

    /**
     * Apply graceful degradation to failed processing
     */
    public function applyGracefulDegradation(string $processingId): ProcessingResult
    {
        try {
            $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

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

            $sermon = Sermon::find($processingLog->sermon_id);
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
                'status' => ProcessingStatus::COMPLETED,
                'current_step' => 'completed_with_degradation',
                'error_message' => 'Graceful degradation applied',
            ]);

            $this->logger->logProcessingCompletion($processingId, true, 'Graceful degradation applied');

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Graceful degradation applied successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingId]),
                details: [
                    'sermon_id' => $sermon->id,
                    'applied_fallbacks' => $fallbackData,
                    'degradation_applied_at' => now()->toISOString(),
                ]
            );

        } catch (\Exception $e) {
            Log::error('Failed to apply graceful degradation', [
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
     * Cancel processing for a given processing ID
     */
    public function cancelProcessing(string $processingId): bool
    {
        try {
            $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

            if (! $processingLog) {
                return false;
            }

            // If processing is already completed, cannot cancel
            if ($processingLog->status === ProcessingStatus::COMPLETED) {
                return false;
            }

            $processingLog->markAsCancelled('Processing cancelled by user');

            $this->logger->logProcessingCompletion($processingId, false, 'Processing cancelled by user');

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cancel processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
