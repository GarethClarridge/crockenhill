<?php

namespace App\Services;

use App\Enums\ProcessingStatus;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;
use App\Jobs\UpdateSermonRecord;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class SermonJobPipelineService
{
    public function __construct(
        private ProcessingPipelineBuilder $pipelineBuilder
    ) {}

    /**
     * Create and dispatch processing jobs for the sermon
     */
    public function dispatchProcessingJobs(
        array $jobs,
        MediaProcessingLog $processingLog,
        array $livestreamMetadata = []
    ): void {
        Log::info('Dispatching sermon processing jobs', [
            'processing_id' => $processingLog->processing_id,
            'jobs_count' => count($jobs),
            'source_type' => $livestreamMetadata['source_type'] ?? 'unknown',
        ]);

        // For livestream audio, use a different queue to avoid conflicts
        $queueName = $this->isLivestreamAudio($livestreamMetadata) ? 'livestream-audio' : 'default';

        Bus::chain($jobs)
            ->catch(function (\Throwable $e) use ($processingLog) {
                Log::error('Sermon processing job chain failed', [
                    'processing_id' => $processingLog->processing_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Update processing log with error
                $processingLog->update([
                    'status' => ProcessingStatus::FAILED,
                    'error_message' => 'Processing chain failed: '.$e->getMessage(),
                    'current_step' => 'job_chain_failed',
                ]);
            })
            ->onQueue($queueName)
            ->dispatch();
    }

    /**
     * Create initial processing log entry with livestream context
     */
    public function createProcessingLogWithLivestreamContext(
        string $processingId,
        string $originalFilename,
        array $livestreamMetadata
    ): MediaProcessingLog {
        $logData = [
            'processing_id' => $processingId,
            'original_filename' => $originalFilename,
            'owner_user_id' => Auth::id(),
            'status' => ProcessingStatus::PENDING,
            'current_step' => 'initiated_from_livestream',
        ];

        // For now, we'll store livestream context in the current_step field
        // In the future, a processing_metadata JSON field could be added to the migration
        if (! empty($livestreamMetadata['livestream_processing_id'])) {
            $logData['current_step'] = 'initiated_from_livestream:'.$livestreamMetadata['livestream_processing_id'];
        }

        return MediaProcessingLog::create($logData);
    }

    /**
     * Restart processing chain from the appropriate point
     */
    public function restartProcessingChain(MediaProcessingLog $processingLog): void
    {
        $currentStep = $processingLog->current_step;
        $sermonId = $processingLog->sermon_id;

        Log::info('Restarting processing chain', [
            'processing_id' => $processingLog->processing_id,
            'current_step' => $currentStep,
            'sermon_id' => $sermonId,
        ]);

        // Determine which job to restart based on the failed step
        switch ($currentStep) {
            case 'preparing':
            case 'retry_initiated': // Legacy fallback for backwards compatibility
                // Early step failures need to restart from the beginning
                $this->restartFromBeginning($processingLog);
                break;

            case 'creating_sermon_record':
            case 'creating_sermon_record_failed':
                // Restart from the beginning - but we need the original metadata
                // For now, we'll mark for manual review since we can't easily recreate the metadata
                $this->markForManualReview($processingLog->processing_id, 'Failed during sermon record creation - requires manual intervention');
                break;

            case 'transcribing_audio':
            case 'transcribing_audio_failed':
                if ($sermonId) {
                    TranscribeAudio::dispatch($processingLog)
                        ->onQueue(config('media-processing.processing.queue', 'default'));
                }
                break;

            case 'analyzing_transcript':
            case 'analyzing_transcript_failed':
                if ($sermonId) {
                    ProcessTranscriptWithAI::dispatch($processingLog)
                        ->onQueue(config('media-processing.processing.queue', 'default'));
                }
                break;

            case 'updating_sermon_record':
            case 'updating_sermon_record_failed':
                if ($sermonId) {
                    UpdateSermonRecord::dispatch($sermonId)
                        ->onQueue(config('media-processing.processing.queue', 'default'));
                }
                break;

            case 'sending_notification':
            case 'notification_failed':
                if ($sermonId) {
                    SendCompletionNotification::dispatch($processingLog)
                        ->onQueue(config('media-processing.processing.queue', 'default'));
                }
                break;

            default:
                // Unknown step - mark for manual review
                $this->markForManualReview($processingLog->processing_id, "Unknown processing step: {$currentStep}");
                break;
        }
    }

    /**
     * Check if processing can be retried automatically
     */
    public function canRetryProcessing(MediaProcessingLog $processingLog): bool
    {
        // Don't retry if it's been marked for manual review
        if (str_contains($processingLog->current_step ?? '', 'manual_review')) {
            return false;
        }

        // Don't retry if it's too old (more than 7 days)
        if ($processingLog->created_at->diffInDays(now()) > 7) {
            return false;
        }

        // Don't retry certain critical failures
        $criticalFailures = [
            'file_not_found',
            'invalid_file_format',
            'storage_failure',
        ];

        foreach ($criticalFailures as $failure) {
            if (str_contains(strtolower($processingLog->error_message ?? ''), $failure)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if processing requires manual review
     */
    public function requiresManualReview(MediaProcessingLog $processingLog): bool
    {
        // Already marked for manual review
        if (str_contains($processingLog->current_step ?? '', 'manual_review')) {
            return true;
        }

        // Multiple failures in critical steps
        $criticalSteps = [
            'creating_sermon_record',
            'transcribing_audio',
        ];

        if (in_array($processingLog->current_step, $criticalSteps)) {
            return true;
        }

        // Check for specific error patterns that require manual intervention
        $manualReviewPatterns = [
            'file not found',
            'invalid audio format',
            'transcription service unavailable',
            'storage failure',
            'database constraint violation',
        ];

        $errorMessage = strtolower($processingLog->error_message ?? '');
        foreach ($manualReviewPatterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark processing for manual review
     */
    private function markForManualReview(string $processingId, string $reviewNote = ''): bool
    {
        try {
            $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

            if (! $processingLog) {
                Log::warning('Processing log not found for manual review marking', [
                    'processing_id' => $processingId,
                ]);

                return false;
            }

            $processingLog->update([
                'status' => ProcessingStatus::FAILED,
                'current_step' => 'manual_review_required',
                'error_message' => $reviewNote ?: 'Marked for manual review',
            ]);

            Log::info('Processing marked for manual review', [
                'processing_id' => $processingId,
                'review_note' => $reviewNote,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to mark processing for manual review', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if this is livestream audio processing
     */
    private function isLivestreamAudio(array $metadata): bool
    {
        return isset($metadata['source_type']) &&
               in_array($metadata['source_type'], ['livestream', 'video_upload']);
    }

    /**
     * Build sermon processing pipeline using the builder
     */
    public function buildSermonProcessingPipeline(
        MediaProcessingLog $processingLog,
        string $absolutePath,
        array $livestreamMetadata = []
    ): array {
        return $this->pipelineBuilder->buildAudioPipeline($processingLog);
    }

    /**
     * Retry processing based on current step
     */
    public function retryProcessing(string $processingId): ProcessingResult
    {
        try {
            Log::info('Attempting to retry failed processing', [
                'processing_id' => $processingId,
            ]);

            $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

            if (! $processingLog) {
                Log::warning('Processing log not found for retry', [
                    'processing_id' => $processingId,
                ]);

                return ProcessingResult::failure(
                    processingId: $processingId,
                    message: 'Processing log not found',
                    errorCode: 'PROCESSING_LOG_NOT_FOUND'
                );
            }

            // Log processing context for better debugging
            Log::info('Found processing log for retry', [
                'processing_id' => $processingId,
                'current_status' => $processingLog->status,
                'current_step' => $processingLog->current_step,
                'processing_type' => $processingLog->processing_type,
                'original_filename' => $processingLog->original_filename,
                'last_error' => $processingLog->error_message,
                'created_at' => $processingLog->created_at,
                'updated_at' => $processingLog->updated_at,
            ]);

            if (! $processingLog->status->isRetryable()) {
                return ProcessingResult::failure(
                    processingId: $processingId,
                    message: 'Processing is not in failed or cancelled state',
                    errorCode: 'PROCESSING_NOT_FAILED'
                );
            }

            // Store original failed step for proper restart context
            $originalFailedStep = $processingLog->current_step;

            // Reset processing log to pending state, preserving original step
            $processingLog->update([
                'status' => ProcessingStatus::PENDING,
                'error_message' => null,
            ]);

            Log::info('Processing retry - preserving original failed step', [
                'processing_id' => $processingId,
                'original_failed_step' => $originalFailedStep,
            ]);

            // Determine where to restart the processing chain based on current step
            $this->restartProcessingChain($processingLog);

            Log::info('Processing retry initiated successfully', [
                'processing_id' => $processingId,
            ]);

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Processing retry initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingId])
            );
        } catch (\Exception $e) {
            Log::error('Failed to retry processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ProcessingResult::failure(
                processingId: $processingId,
                message: 'Failed to retry processing: '.$e->getMessage(),
                errorCode: 'RETRY_FAILED'
            );
        }
    }

    /**
     * Restart processing from the beginning for early step failures
     *
     * This method handles cases where processing failed before the main pipeline started,
     * such as during file preparation or initial routing.
     */
    private function restartFromBeginning(MediaProcessingLog $processingLog): void
    {
        Log::info('Restarting processing from beginning', [
            'processing_id' => $processingLog->processing_id,
            'processing_type' => $processingLog->processing_type,
            'original_filename' => $processingLog->original_filename,
        ]);

        try {
            // For early failures, we need to check if we have enough context to restart
            // The key challenge is that the original file may not be available

            $sourceType = $processingLog->processing_type;

            // Update step to indicate we're attempting restart
            $processingLog->update([
                'current_step' => 'restarting_from_beginning',
            ]);

            // For now, we'll mark for manual review since full restart requires
            // the original file which may not be available after early failures
            // In the future, this could be enhanced to store file paths for restart
            $this->markForManualReview(
                $processingLog->processing_id,
                "Early processing failure detected. Source type: {$sourceType}. ".
                'File may need to be re-uploaded for retry. '.
                "Original filename: {$processingLog->original_filename}"
            );

            Log::info('Marked early failure for manual review', [
                'processing_id' => $processingLog->processing_id,
                'reason' => 'Early step failure requires file re-upload',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to restart processing from beginning', [
                'processing_id' => $processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            $this->markForManualReview(
                $processingLog->processing_id,
                "Failed to restart early processing failure: {$e->getMessage()}"
            );
        }
    }
}
