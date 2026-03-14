<?php

namespace App\Services;

use App\Contracts\ProvidesSafeMessage;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;
use App\Jobs\UpdateSermonRecord;
use App\Models\MediaProcessingLog;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class SermonJobPipelineService
{
    public function __construct(
        private readonly SermonValidationService $validationService
    ) {}

    /**
     * Create and dispatch processing jobs for the sermon
     *
     * @param  array<int, mixed>  $jobs
     * @param  array<string, mixed>  $livestreamMetadata
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

        $queueName = $this->isLivestreamAudio($livestreamMetadata)
            ? $this->livestreamAudioQueue()
            : $this->defaultQueue();

        Bus::chain($jobs)
            ->catch(function (\Throwable $e) use ($processingLog) {
                $this->handleJobChainFailure($processingLog, $e);
            })
            ->onQueue($queueName)
            ->dispatch();
    }

    /**
     * Handle failures in the processing job chain.
     */
    private function handleJobChainFailure(MediaProcessingLog $processingLog, \Throwable $e): void
    {
        Log::error('Sermon processing job chain failed', [
            'processing_id' => $processingLog->processing_id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $message = $e instanceof ProvidesSafeMessage
            ? $e->getSafeMessage()
            : 'An internal error occurred during the processing chain.';

        // Update processing log with error
        $processingLog->update([
            'status' => ProcessingStatus::FAILED,
            'error_message' => "Processing chain failed: {$message}",
            'current_step' => 'job_chain_failed',
        ]);
    }

    /**
     * Create initial processing log entry with livestream context
     *
     * @param  array<string, mixed>  $livestreamMetadata
     */
    public function createProcessingLogWithLivestreamContext(
        string $processingId,
        string $originalFilename,
        array $livestreamMetadata
    ): MediaProcessingLog {
        $logData = [
            'processing_id' => $processingId,
            'processing_type' => MediaType::Audio,
            'original_filename' => $originalFilename,
            'owner_user_id' => Auth::id(),
            'status' => ProcessingStatus::PENDING,
            'current_step' => 'initiated_from_livestream',
        ];

        if (! empty($livestreamMetadata)) {
            $logData['processing_metadata'] = $livestreamMetadata;
        }

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
                $processingLog->markForManualReview('Failed during sermon record creation - requires manual intervention');
                break;

            case 'transcribing_audio':
            case 'transcribing_audio_failed':
                if ($sermonId) {
                    TranscribeAudio::dispatch($processingLog)
                        ->onQueue($this->processingQueue());
                }
                break;

            case 'analyzing_transcript':
            case 'analyzing_transcript_failed':
                if ($sermonId) {
                    ProcessTranscriptWithAI::dispatch($processingLog)
                        ->onQueue($this->processingQueue());
                }
                break;

            case 'updating_sermon_record':
            case 'updating_sermon_record_failed':
                if ($sermonId) {
                    UpdateSermonRecord::dispatch($sermonId)
                        ->onQueue($this->processingQueue());
                }
                break;

            case 'sending_notification':
            case 'notification_failed':
                if ($sermonId) {
                    SendCompletionNotification::dispatch($processingLog)
                        ->onQueue($this->processingQueue());
                }
                break;

            default:
                // Unknown step - mark for manual review
                $processingLog->markForManualReview("Unknown processing step: {$currentStep}");
                break;
        }
    }

    /**
     * Check if processing can be retried automatically
     */
    public function canRetryProcessing(MediaProcessingLog $processingLog): bool
    {
        return $this->validationService->canRetryProcessing($processingLog);
    }

    /**
     * Check if processing requires manual review
     */
    public function requiresManualReview(MediaProcessingLog $processingLog): bool
    {
        return $this->validationService->requiresManualReview($processingLog);
    }

    /**
     * Check if this is livestream audio processing
     *
     * @param  array<string, mixed>  $metadata
     */
    private function isLivestreamAudio(array $metadata): bool
    {
        return isset($metadata['source_type']) &&
               in_array($metadata['source_type'], ['livestream', 'video_upload']);
    }

    private function livestreamAudioQueue(): string
    {
        return (string) config(
            'media-processing.queues.livestream_audio',
            config('media-processing.types.audio.queue', 'audio-processing')
        );
    }

    private function defaultQueue(): string
    {
        return (string) config('media-processing.queues.default', 'default');
    }

    private function processingQueue(): string
    {
        return (string) config('media-processing.queues.processing', $this->defaultQueue());
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
        } catch (Exception $e) {
            Log::error('Failed to retry processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = $e instanceof ProvidesSafeMessage
                ? $e->getSafeMessage()
                : 'An internal error occurred while attempting to retry processing.';

            return ProcessingResult::failure(
                processingId: $processingId,
                message: "Failed to retry processing: {$message}",
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
            'processing_type' => $processingLog->processing_type->value,
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
            $processingLog->markForManualReview(
                "Early processing failure detected. Source type: {$sourceType->value}. ".
                'File may need to be re-uploaded for retry. '.
                "Original filename: {$processingLog->original_filename}"
            );

            Log::info('Marked early failure for manual review', [
                'processing_id' => $processingLog->processing_id,
                'reason' => 'Early step failure requires file re-upload',
            ]);

        } catch (Exception $e) {
            Log::error('Failed to restart processing from beginning', [
                'processing_id' => $processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            $processingLog->markForManualReview("Failed to restart early processing failure: {$e->getMessage()}");
        }
    }
}
