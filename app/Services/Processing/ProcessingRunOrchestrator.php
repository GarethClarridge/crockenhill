<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Contracts\ProvidesSafeMessage;
use App\Data\HistoricStagingContext;
use App\Data\ProcessingResult;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
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
 */
class ProcessingRunOrchestrator
{
    public function __construct(
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
        private readonly ProcessingPhaseRegistry $phaseRegistry,
        private readonly ProcessingPhaseResetService $phaseResetService,
        private readonly MediaProcessingRunTransitionService $processingRunTransitions,
        private readonly VideoStorageService $videoStorageService,
        private readonly HistoricProcessingThroughput $historicProcessingThroughput,
        private readonly HistoricStagingContextRegistry $stagingContextRegistry,
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
                $processingLog,
                ProcessingRunFailureHandler::PROFILE_AUDIO
            ),
            'video' => $this->dispatchChain(
                $this->pipelineBuilder->buildDirectVideoPipeline($processingLog),
                $this->videoQueue(),
                $processingLog,
                ProcessingRunFailureHandler::PROFILE_VIDEO
            ),
            'video_auto_trim' => $this->dispatchChain(
                $this->pipelineBuilder->buildAutoTrimVideoPipeline($processingLog),
                $this->videoQueue(),
                $processingLog,
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
                $processingLog,
                ProcessingRunFailureHandler::PROFILE_LIVESTREAM
            ),
            'video_auto_trim' => $this->dispatchChain(
                $this->pipelineBuilder->buildAutoTrimVideoPostReviewChainJobs($processingLog),
                $this->videoQueue(),
                $processingLog,
                ProcessingRunFailureHandler::PROFILE_VIDEO_AUTO_TRIM
            ),
            default => throw new \InvalidArgumentException('Manual sermon review is only available for segmentation-style processing runs.'),
        };
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
            $stagingContext = $processingLog->historicStagingContext();

            if ($stagingContext instanceof HistoricStagingContext) {
                return $this->stagingContextRegistry->within(
                    $stagingContext,
                    fn (): ProcessingResult => $this->runRetry($processingLog),
                );
            }

            if ($processingLog->historicImportJobKey() !== null && ! $this->stagingContextRegistry->isActive()) {
                return ProcessingResult::failure(
                    processingId: $processingLog->processing_id,
                    message: 'This historic run records no staging context, so a retry cannot resolve its retained artifacts.',
                    errorCode: 'HISTORIC_STAGING_CONTEXT_MISSING'
                );
            }

            return $this->runRetry($processingLog);
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
     * Resolve and dispatch the retry plan. Always runs with the run's historic staging
     * context active when it has one, so artifact keys resolve against the same batch root
     * the original attempt wrote them under.
     */
    private function runRetry(MediaProcessingLog $processingLog): ProcessingResult
    {
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
            default => throw new \InvalidArgumentException(
                'Unknown retry plan action: '.get_debug_type($retryPlan['action'])
            ),
        };
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
    private function dispatchChain(array $jobs, string $queueName, MediaProcessingLog $processingLog, string $failureProfile): void
    {
        $processingId = $processingLog->processing_id;

        if (! $this->shouldDispatch($processingId)) {
            return;
        }

        $mainChainId = $this->historicMainChainId($processingLog);
        $chain = Bus::chain($mainChainId === null ? $jobs : $this->assignHistoricQueues($jobs))
            ->catch(function (\Throwable $exception) use ($processingId, $failureProfile): void {
                /**
                 * Late-resolve the failure handler instead of capturing $this so that the
                 * closure remains safely serializable by Laravel's queue infrastructure
                 * (the orchestrator's dependency graph may contain non-serializable
                 * collaborators like swapped-in test doubles).
                 */
                app(ProcessingRunFailureHandler::class)->handle($processingId, $exception, $failureProfile);
            });

        /**
         * A historic chain carries a per-job queue from `assignHistoricQueues`,
         * so naming one queue for the whole chain here would flatten the
         * per-stage routing back into a single pool.
         */
        if ($mainChainId === null) {
            $chain->onQueue($queueName);
        }

        $chain->dispatch();

        $this->recordHistoricQueueDispatch($processingId, $mainChainId, mainChainDispatched: true);
    }

    private function dispatchLivestreamStart(MediaProcessingLog $processingLog): void
    {
        $queueName = $this->livestreamQueue();
        $processingId = $processingLog->processing_id;
        $mainChainId = $this->historicMainChainId($processingLog);
        $parallelJobs = $this->pipelineBuilder->buildLivestreamParallelJobs($processingLog);
        $chainJobs = $this->pipelineBuilder->buildLivestreamChainJobs($processingLog);

        $batch = Bus::batch($parallelJobs)
            ->then(function (Batch $batch) use ($chainJobs, $queueName, $processingId): void {
                $processingLog = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

                if (! $processingLog instanceof MediaProcessingLog || ! $this->shouldDispatch($processingId)) {
                    return;
                }

                $this->dispatchChain(
                    $chainJobs,
                    $queueName,
                    $processingLog,
                    ProcessingRunFailureHandler::PROFILE_LIVESTREAM,
                );
            })
            ->catch(function (Batch $batch, \Throwable $exception) use ($processingId): void {
                app(ProcessingRunFailureHandler::class)->handle(
                    $processingId,
                    $exception,
                    ProcessingRunFailureHandler::PROFILE_LIVESTREAM
                );
            })
            ->onQueue($mainChainId === null ? $queueName : $this->historicProcessingThroughput->fanOutQueue())
            ->dispatch();

        if ($mainChainId !== null) {
            $this->recordHistoricQueueDispatch($processingId, $mainChainId, $batch->id);
        }
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
            $processingLog,
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
     * @param  RetryPlan  $retryPlan
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
     * @param  RetryPlan  $retryPlan
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

    private function historicMainChainId(MediaProcessingLog $processingLog): ?string
    {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $jobKey = data_get($metadata, 'historic_import.job_key');

        if (! is_string($jobKey) || $jobKey === '') {
            return null;
        }

        return hash('sha256', "historic-main-chain\0{$jobKey}");
    }

    /**
     * @param  array<int, object>  $jobs
     * @return array<int, object>
     */
    private function assignHistoricQueues(array $jobs): array
    {
        foreach ($jobs as $job) {
            if (! method_exists($job, 'onQueue')) {
                throw new \LogicException('Historic processing job '.get_class($job).' cannot be routed to a calibrated queue.');
            }

            $job->onQueue($this->historicProcessingThroughput->queueFor($job));
        }

        return $jobs;
    }

    /**
     * Record what a historic run was dispatched as, so the readiness auditor can
     * wait for the whole shape of the work rather than just the parent log. The
     * row is re-read rather than written through the caller's instance because
     * the pipeline may have advanced it since.
     */
    private function recordHistoricQueueDispatch(
        string $processingId,
        ?string $mainChainId,
        ?string $fanOutBatchId = null,
        bool $mainChainDispatched = false,
    ): void {
        if ($mainChainId === null && $fanOutBatchId === null) {
            return;
        }

        $processingLog = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $historicImport = $metadata['historic_import'] ?? null;

        if (! is_array($historicImport) || ! is_string($historicImport['job_key'] ?? null)) {
            return;
        }

        $queue = $historicImport['queue'] ?? [];
        $queue = is_array($queue) ? $queue : [];

        if ($mainChainId !== null) {
            $queue['main_chain_id'] = $mainChainId;
        }

        if ($fanOutBatchId !== null) {
            $queue['fan_out_batch_id'] = $fanOutBatchId;
        }

        if ($mainChainDispatched) {
            $queue['main_chain_dispatched_at'] = now()->toISOString();
        }

        $historicImport['queue'] = $queue;
        $metadata['historic_import'] = $historicImport;
        $processingLog->forceFill(['processing_metadata' => $metadata])->save();
    }
}
