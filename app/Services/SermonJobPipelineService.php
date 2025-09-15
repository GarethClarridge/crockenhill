<?php

namespace App\Services;

use App\Enums\ProcessingStatus;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;
use App\Jobs\UpdateSermonRecord;
use App\Models\SermonProcessingLog;
use App\Services\ProcessingPipelineBuilder;
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
        SermonProcessingLog $processingLog,
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
    ): SermonProcessingLog {
        $logData = [
            'processing_id' => $processingId,
            'original_filename' => $originalFilename,
            'status' => ProcessingStatus::PENDING,
            'current_step' => 'initiated_from_livestream',
        ];

        // For now, we'll store livestream context in the current_step field
        // In the future, a processing_metadata JSON field could be added to the migration
        if (! empty($livestreamMetadata['livestream_processing_id'])) {
            $logData['current_step'] = 'initiated_from_livestream:'.$livestreamMetadata['livestream_processing_id'];
        }

        return SermonProcessingLog::create($logData);
    }

    /**
     * Restart processing chain from the appropriate point
     */
    public function restartProcessingChain(SermonProcessingLog $processingLog): void
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
            case 'creating_sermon_record':
            case 'creating_sermon_record_failed':
                // Restart from the beginning - but we need the original metadata
                // For now, we'll mark for manual review since we can't easily recreate the metadata
                $this->markForManualReview($processingLog->processing_id, 'Failed during sermon record creation - requires manual intervention');
                break;

            case 'transcribing_audio':
            case 'transcribing_audio_failed':
                if ($sermonId) {
                    TranscribeAudio::dispatch($sermonId)
                        ->onQueue(config('sermon-processing.processing.queue', 'default'));
                }
                break;

            case 'analyzing_transcript':
            case 'analyzing_transcript_failed':
                if ($sermonId) {
                    ProcessTranscriptWithAI::dispatch($sermonId)
                        ->onQueue(config('sermon-processing.processing.queue', 'default'));
                }
                break;

            case 'updating_sermon_record':
            case 'updating_sermon_record_failed':
                if ($sermonId) {
                    UpdateSermonRecord::dispatch($sermonId)
                        ->onQueue(config('sermon-processing.processing.queue', 'default'));
                }
                break;

            case 'sending_notification':
            case 'notification_failed':
                if ($sermonId) {
                    SendCompletionNotification::dispatch($sermonId)
                        ->onQueue(config('sermon-processing.processing.queue', 'default'));
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
    public function canRetryProcessing(SermonProcessingLog $processingLog): bool
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
    public function requiresManualReview(SermonProcessingLog $processingLog): bool
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
            $processingLog = SermonProcessingLog::where('processing_id', $processingId)->first();

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
        SermonProcessingLog $processingLog,
        string $absolutePath,
        array $livestreamMetadata = []
    ): array {
        return $this->pipelineBuilder->buildSermonProcessingPipeline($processingLog, $absolutePath, $livestreamMetadata);
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

            $processingLog = SermonProcessingLog::where('processing_id', $processingId)->first();

            if (! $processingLog) {
                return ProcessingResult::failure(
                    processingId: $processingId,
                    message: 'Processing log not found',
                    errorCode: 'PROCESSING_LOG_NOT_FOUND'
                );
            }

            if (! $processingLog->isFailed()) {
                return ProcessingResult::failure(
                    processingId: $processingId,
                    message: 'Processing is not in failed state',
                    errorCode: 'PROCESSING_NOT_FAILED'
                );
            }

            // Reset processing log to pending state
            $processingLog->update([
                'status' => ProcessingStatus::PENDING,
                'current_step' => 'retry_initiated',
                'error_message' => null,
            ]);

            // Determine where to restart the processing chain based on current step
            $this->restartProcessingChain($processingLog);

            Log::info('Processing retry initiated successfully', [
                'processing_id' => $processingId,
            ]);

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Processing retry initiated successfully',
                statusUrl: route('api.sermons.processing.status', ['processingId' => $processingId])
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
}