<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Contracts\ProvidesSafeMessage;
use App\Data\ProcessingResult;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Central dispatcher and state manager for media processing runs.
 *
 * Orchestrates the execution of media processing pipelines by dispatching
 * job chains or batches to their appropriate queues based on the media type.
 * Handles the logic for starting fresh runs, resuming after manual review,
 * retrying failed/cancelled runs, and user-initiated cancellations.
 *
 * @phpstan-import-type RetryPlan from ProcessingPhaseRegistry
 * @phpstan-import-type ChainRetryPlan from ProcessingPhaseRegistry
 * @phpstan-import-type ManualReviewPlan from ProcessingPhaseRegistry
 */
class ProcessingRunOrchestrator
{
    public function __construct(
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
        private readonly ProcessingPhaseRegistry $phaseRegistry,
        private readonly ProcessingPhaseResetService $phaseResetService,
        private readonly MediaProcessingRunTransitionService $processingRunTransitions,
        private readonly VideoStorageService $videoStorageService,
    ) {}

    /**
     * Start a new media processing run.
     *
     * Dispatches the appropriate job pipeline based on the log's profile.
     * Audio and direct video runs use linear chains, while livestream runs
     * start with a parallel processing phase before chaining.
     *
     * @param  MediaProcessingLog  $processingLog  The log record for the run to start
     *
     * @throws \InvalidArgumentException If the processing pipeline profile is unrecognized
     * @throws \Throwable If dispatching the job chain or batch fails
     */
    public function start(MediaProcessingLog $processingLog): void
    {
        match ($processingLog->processingPipelineProfile()) {
            'audio' => $this->dispatchChain(
                $this->pipelineBuilder->buildAudioPipeline($processingLog),
                $this->audioQueue(),
                $processingLog->processing_id,
                ProcessingRunFailureHandler::PROFILE_AUDIO
            ),
            'video' => $this->dispatchChain(
                $this->pipelineBuilder->buildDirectVideoPipeline($processingLog),
                $this->videoQueue(),
                $processingLog->processing_id,
                ProcessingRunFailureHandler::PROFILE_VIDEO
            ),
            'video_auto_trim' => $this->dispatchChain(
                $this->pipelineBuilder->buildAutoTrimVideoPipeline($processingLog),
                $this->videoQueue(),
                $processingLog->processing_id,
                ProcessingRunFailureHandler::PROFILE_VIDEO_AUTO_TRIM
            ),
            'livestream' => $this->dispatchLivestreamStart($processingLog),
            default => throw new \InvalidArgumentException(
                'Unknown processing pipeline profile: '.$processingLog->processingPipelineProfile()
            ),
        };
    }

    /**
     * Resume a processing run that was paused for manual sermon segment confirmation.
     *
     * Only applicable to 'livestream' and 'video_auto_trim' profiles which
     * utilize the segmentation-based pipeline.
     *
     * @param  MediaProcessingLog  $processingLog  The log record awaiting resumption
     *
     * @throws \InvalidArgumentException If manual review is not supported for this profile
     * @throws \Throwable If dispatching the resumed job chain fails
     */
    public function resumeAfterManualReview(MediaProcessingLog $processingLog): void
    {
        match ($processingLog->processingPipelineProfile()) {
            'livestream' => $this->dispatchChain(
                $this->pipelineBuilder->buildLivestreamPostReviewChainJobs($processingLog),
                $this->livestreamQueue(),
                $processingLog->processing_id,
                ProcessingRunFailureHandler::PROFILE_LIVESTREAM
            ),
            'video_auto_trim' => $this->dispatchChain(
                $this->pipelineBuilder->buildAutoTrimVideoPostReviewChainJobs($processingLog),
                $this->videoQueue(),
                $processingLog->processing_id,
                ProcessingRunFailureHandler::PROFILE_VIDEO_AUTO_TRIM
            ),
            default => throw new \InvalidArgumentException('Manual sermon review is only available for segmentation-style processing runs.'),
        };
    }

    /**
     * Re-run the classification and structural analysis for an existing livestream run.
     *
     * Used when the original source media is still available to refresh
     * sermon-derived outputs or correct manual classification errors.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to reclassify
     *
     * @throws \Throwable If dispatching the reclassification job chain fails
     */
    public function reclassify(MediaProcessingLog $processingLog): void
    {
        $this->dispatchChain(
            $this->pipelineBuilder->buildSectionReclassificationChainJobs($processingLog),
            $this->livestreamQueue(),
            $processingLog->processing_id,
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM
        );
    }

    /**
     * Attempt to retry a failed or cancelled processing run.
     *
     * Consults the PhaseRegistry to determine a surgical retry plan (either
     * resuming from a specific job offset, targeted reset, or full restart)
     * based on the run's last successful state.
     *
     * @param  MediaProcessingLog  $processingLog  The failed or cancelled log
     * @return ProcessingResult The outcome of the retry initiation attempt
     *
     * @throws \Throwable For unexpected orchestration failures
     */
    public function retry(MediaProcessingLog $processingLog): ProcessingResult
    {
        try {
            if (! $processingLog->status->isRetryable()) {
                return ProcessingResult::failure(
                    processingId: $processingLog->processing_id,
                    message: 'Processing is not in failed or cancelled state',
                    errorCode: 'PROCESSING_NOT_FAILED'
                );
            }

            /** @var RetryPlan $retryPlan */
            $retryPlan = $this->phaseRegistry->retryPlanFor($processingLog);

            return match ($retryPlan['action']) {
                'dispatch_chain', 'dispatch_livestream_chain' => $this->retryWithChainFromPlan($processingLog, $retryPlan),
                'restart_livestream' => $this->restartLivestream($processingLog),
                'manual_review' => $this->markForManualReviewFromPlan($processingLog, $retryPlan),
            };
        } catch (\Throwable $exception) {
            Log::error('Failed to retry processing run', [
                'processing_id' => $processingLog->processing_id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            $message = $exception instanceof ProvidesSafeMessage
                ? $exception->getSafeMessage()
                : 'An internal error occurred while attempting to retry processing.';

            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: "Failed to retry processing: {$message}",
                errorCode: 'RETRY_FAILED'
            );
        }
    }

    /**
     * Terminate an active processing run.
     *
     * Marks the run as cancelled and, for segmentation-style pipelines,
     * triggers a cleanup of associated temporary files.
     *
     * @param  MediaProcessingLog  $processingLog  The log record for the run to cancel
     * @return bool True if the cancellation transition was successful
     */
    public function cancel(MediaProcessingLog $processingLog): bool
    {
        if ($processingLog->status === ProcessingStatus::Completed) {
            return false;
        }

        $cancelled = $this->processingRunTransitions->markAsCancelled(
            $processingLog,
            'Processing cancelled by user'
        );

        if (! $cancelled) {
            return false;
        }

        if ($processingLog->usesSegmentationPipeline()) {
            $this->cleanupSegmentationFiles($processingLog->fresh() ?? $processingLog);
        }

        return true;
    }

    /**
     * @param  array<int, object>  $jobs
     */
    private function dispatchChain(array $jobs, string $queueName, string $processingId, string $failureProfile): void
    {
        if (! $this->shouldDispatch($processingId)) {
            return;
        }

        Bus::chain($jobs)
            ->catch(function (\Throwable $exception) use ($processingId, $failureProfile): void {
                /**
                 * Late-resolve the failure handler instead of capturing $this so that the
                 * closure remains safely serializable by Laravel's queue infrastructure
                 * (the orchestrator's dependency graph may contain non-serializable
                 * collaborators like swapped-in test doubles).
                 */
                app(ProcessingRunFailureHandler::class)->handle($processingId, $exception, $failureProfile);
            })
            ->onQueue($queueName)
            ->dispatch();
    }

    private function dispatchLivestreamStart(MediaProcessingLog $processingLog): void
    {
        $queueName = $this->livestreamQueue();
        $processingId = $processingLog->processing_id;
        $parallelJobs = $this->pipelineBuilder->buildLivestreamParallelJobs($processingLog);
        $chainJobs = $this->pipelineBuilder->buildLivestreamChainJobs($processingLog);

        Bus::batch($parallelJobs)
            ->then(function (Batch $batch) use ($chainJobs, $queueName, $processingId): void {
                if (! $this->shouldDispatch($processingId)) {
                    return;
                }

                Bus::chain($chainJobs)
                    ->catch(function (\Throwable $exception) use ($processingId): void {
                        app(ProcessingRunFailureHandler::class)->handle(
                            $processingId,
                            $exception,
                            ProcessingRunFailureHandler::PROFILE_LIVESTREAM
                        );
                    })
                    ->onQueue($queueName)
                    ->dispatch();
            })
            ->catch(function (Batch $batch, \Throwable $exception) use ($processingId): void {
                app(ProcessingRunFailureHandler::class)->handle(
                    $processingId,
                    $exception,
                    ProcessingRunFailureHandler::PROFILE_LIVESTREAM
                );
            })
            ->onQueue($queueName)
            ->dispatch();
    }

    private function retryWithChain(MediaProcessingLog $processingLog, string $pipeline, int $jobOffset): ProcessingResult
    {
        $this->processingRunTransitions->resetForRetry($processingLog);

        $freshLog = $processingLog->fresh() ?? $processingLog;

        $jobs = match ($pipeline) {
            'audio' => array_slice($this->pipelineBuilder->buildAudioPipeline($freshLog), $jobOffset),
            'video' => array_slice($this->pipelineBuilder->buildDirectVideoPipeline($freshLog), $jobOffset),
            'video_auto_trim' => array_slice($this->pipelineBuilder->buildAutoTrimVideoPipeline($freshLog), $jobOffset),
            'livestream' => array_slice($this->pipelineBuilder->buildLivestreamChainJobs($freshLog), $jobOffset),
            default => [],
        };

        if ($jobs === []) {
            Log::warning('Retry plan resolved to an empty job chain', [
                'processing_id' => $processingLog->processing_id,
                'pipeline' => $pipeline,
                'job_offset' => $jobOffset,
            ]);

            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: 'No retryable work remained for this processing run',
                errorCode: 'EMPTY_RETRY_PLAN'
            );
        }

        $this->dispatchChain(
            $jobs,
            match ($pipeline) {
                'video', 'video_auto_trim' => $this->videoQueue(),
                'livestream' => $this->livestreamQueue(),
                default => $this->audioQueue(),
            },
            $processingLog->processing_id,
            match ($pipeline) {
                'video' => ProcessingRunFailureHandler::PROFILE_VIDEO,
                'video_auto_trim' => ProcessingRunFailureHandler::PROFILE_VIDEO_AUTO_TRIM,
                'livestream' => ProcessingRunFailureHandler::PROFILE_LIVESTREAM,
                default => ProcessingRunFailureHandler::PROFILE_AUDIO,
            }
        );

        return ProcessingResult::success(
            processingId: $processingLog->processing_id,
            message: 'Processing retry initiated successfully',
            statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
        );
    }

    /**
     * @param  ChainRetryPlan  $retryPlan
     */
    private function retryWithChainFromPlan(MediaProcessingLog $processingLog, array $retryPlan): ProcessingResult
    {
        $pipeline = $retryPlan['pipeline'] ?? null;
        $jobOffset = $retryPlan['job_offset'] ?? null;

        if (! is_string($pipeline) || ! is_int($jobOffset)) {
            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: 'Retry plan was incomplete for this processing run',
                errorCode: 'INVALID_RETRY_PLAN'
            );
        }

        $this->phaseResetService->resetForRetry($processingLog, $retryPlan);

        return $this->retryWithChain($processingLog, $pipeline, $jobOffset);
    }

    private function restartLivestream(MediaProcessingLog $processingLog): ProcessingResult
    {
        $this->processingRunTransitions->resetForRetry($processingLog);
        $processingLog->segments()->delete();

        $this->start($processingLog->fresh() ?? $processingLog);

        return ProcessingResult::success(
            processingId: $processingLog->processing_id,
            message: 'Processing retry initiated successfully',
            statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
        );
    }

    private function markForManualReview(
        MediaProcessingLog $processingLog,
        string $reasonCode,
        string $reasonMessage
    ): ProcessingResult {
        $this->processingRunTransitions->resetForRetry($processingLog);
        $this->processingRunTransitions->updateStep($processingLog, 'restarting_from_beginning');
        $this->processingRunTransitions->markForManualReview($processingLog, $reasonCode, $reasonMessage);

        return ProcessingResult::success(
            processingId: $processingLog->processing_id,
            message: 'Processing retry initiated successfully',
            statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
        );
    }

    /**
     * @param  ManualReviewPlan  $retryPlan
     */
    private function markForManualReviewFromPlan(MediaProcessingLog $processingLog, array $retryPlan): ProcessingResult
    {
        $reasonCode = $retryPlan['reason_code'] ?? null;
        $reasonMessage = $retryPlan['reason_message'] ?? null;

        if (! is_string($reasonCode) || ! is_string($reasonMessage)) {
            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: 'Retry plan was incomplete for this processing run',
                errorCode: 'INVALID_RETRY_PLAN'
            );
        }

        return $this->markForManualReview($processingLog, $reasonCode, $reasonMessage);
    }

    private function cleanupSegmentationFiles(MediaProcessingLog $processingLog): void
    {
        $tempFiles = $processingLog->temporaryFilePaths();

        if ($tempFiles !== []) {
            $this->videoStorageService->cleanupTemporaryFiles($tempFiles);
        }
    }

    private function audioQueue(): string
    {
        return (string) config('media-processing.queues.audio', 'audio-processing');
    }

    private function videoQueue(): string
    {
        return (string) config('media-processing.queues.video', config('media-processing.types.video.queue', 'video-processing'));
    }

    private function livestreamQueue(): string
    {
        return (string) config('media-processing.queues.livestream', 'livestream-processing');
    }

    private function shouldDispatch(string $processingId): bool
    {
        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            return false;
        }

        if ($processingLog->isCancelled()) {
            Log::info('Skipping downstream dispatch for cancelled processing run', [
                'processing_id' => $processingId,
            ]);

            return false;
        }

        return true;
    }
}
