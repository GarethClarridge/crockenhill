<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Contracts\ProvidesSafeMessage;
use App\Data\HistoricStagingContext;
use App\Data\ProcessingResult;
use App\Enums\HistoricImportOperationState;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingStep;
use App\Jobs\AwaitHistoricSermonVideoStorage;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\PromoteHistoricAssets;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\HistoricMedia\HistoricPassInFlightProbe;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Video\VideoStorageService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
    /** @var list<string> */
    private const HistoricTailSteps = [
        'notification_sent',
        'notification_skipped',
        'notification_failed',
        'notification_failed_permanently',
        'promoting_historic_assets',
        'cleanup',
    ];

    private const DefaultHistoricTailRecoveryStaleAfterSeconds = 3600;

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
            return $this->withRecordedStagingContext(
                $processingLog,
                fn (): ProcessingResult => $this->runRetry($processingLog),
            );
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
     * Recover only the promotion and cleanup tail of an interrupted historic run.
     *
     * This is deliberately separate from {@see self::retry()}: a stale processing
     * row is not a failed run, and retrying it through the phase registry could
     * repeat paid or otherwise irreversible stages. The caller must name the
     * immutable operation that owns the run; both the foreign key and the
     * operation identity recorded in the run metadata are checked before the two
     * idempotent tail jobs are dispatched.
     */
    public function recoverHistoricTail(
        MediaProcessingLog $processingLog,
        HistoricImportOperation $operation,
    ): ProcessingResult {
        return $this->withRecordedStagingContext($processingLog, function () use ($processingLog, $operation): ProcessingResult {
            return DB::transaction(function () use ($processingLog, $operation): ProcessingResult {
                $lockedLog = MediaProcessingLog::query()
                    ->whereKey($processingLog->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedLog instanceof MediaProcessingLog) {
                    return ProcessingResult::failure(
                        processingId: $processingLog->processing_id,
                        message: 'The processing run disappeared before recovery could be claimed.',
                        errorCode: 'HISTORIC_TAIL_RUN_MISSING',
                    );
                }

                $metadata = $lockedLog->processing_metadata?->toArray() ?? [];
                $claim = $metadata['historic_tail_recovery'] ?? null;
                $validationError = $this->historicTailValidationError(
                    $lockedLog,
                    $operation,
                    checkStaleness: $claim === null,
                    allowFailedRetry: $claim !== null,
                );

                if ($validationError !== null) {
                    return ProcessingResult::failure(
                        processingId: $lockedLog->processing_id,
                        message: $validationError['message'],
                        errorCode: $validationError['code'],
                    );
                }

                if ($claim !== null) {
                    if (! is_array($claim)
                        || ($claim['operation_id'] ?? null) !== $operation->operation_id
                        || ($claim['processing_id'] ?? null) !== $lockedLog->processing_id) {
                        return ProcessingResult::failure(
                            processingId: $lockedLog->processing_id,
                            message: 'The processing run has an invalid or foreign historic tail recovery claim.',
                            errorCode: 'HISTORIC_TAIL_CLAIM_MISMATCH',
                        );
                    }

                    // A claim on a run that is still processing means the chain it
                    // dispatched is genuinely in flight; do not duplicate it.
                    if ($lockedLog->status === ProcessingStatus::Processing) {
                        return ProcessingResult::success(
                            processingId: $lockedLog->processing_id,
                            message: 'Historic promotion and cleanup tail was already claimed; no duplicate chain dispatched.',
                            statusUrl: route('api.media.processing.status', ['processingId' => $lockedLog->processing_id]),
                            details: ['already_claimed' => true],
                        );
                    }

                    // The run failed under its own claim, so that attempt has
                    // settled and nothing is in flight. Re-claiming is what makes
                    // AwaitHistoricSermonVideoStorage's promise true: it fails a
                    // run precisely so that the tail stays recoverable.
                    $this->processingRunTransitions->markAsReopened(
                        $lockedLog,
                        $lockedLog->current_step,
                    );
                    $lockedLog->refresh();
                    $metadata = $lockedLog->processing_metadata?->toArray() ?? [];
                }

                $metadata['historic_tail_recovery'] = [
                    'operation_id' => $operation->operation_id,
                    'processing_id' => $lockedLog->processing_id,
                    'job_key' => $lockedLog->historicImportJobKey(),
                    'claimed_at' => now()->toISOString(),
                ];
                $lockedLog->forceFill(['processing_metadata' => $metadata])->save();

                $pipeline = $lockedLog->processingPipelineProfile();
                $this->dispatchChain(
                    [
                        new AwaitHistoricSermonVideoStorage($lockedLog),
                        new PromoteHistoricAssets($lockedLog),
                        new CleanupTemporaryFiles($lockedLog),
                    ],
                    $this->queueForPipeline($pipeline),
                    $lockedLog,
                    $this->failureProfileForPipeline($pipeline),
                );

                return ProcessingResult::success(
                    processingId: $lockedLog->processing_id,
                    message: 'Historic promotion and cleanup tail dispatched.',
                    statusUrl: route('api.media.processing.status', ['processingId' => $lockedLog->processing_id]),
                );
            });
        });
    }

    /**
     * Re-cut a finished run's sermon from the structure it already has.
     *
     * Confirming or correcting a service's structure changes where the sermon
     * starts and ends, so the published audio has to be rebuilt — but the
     * transcript, RMS log and sections are all still right, and re-running
     * detection would spend an LLM call to produce a different answer, since the
     * detector is not deterministic across passes. So this resumes at the
     * extraction phase, exactly as a retry from that phase would.
     *
     * Unlike {@see self::retry()} it accepts a completed run: that is the whole
     * point. It refuses one whose source media has already been cleaned up, which
     * is the common case for a run that finished — see the command for what an
     * operator can do about that.
     */
    public function reExtract(MediaProcessingLog $processingLog): ProcessingResult
    {
        try {
            return $this->withRecordedStagingContext($processingLog, function () use ($processingLog): ProcessingResult {
                $plan = $this->phaseRegistry->reExtractionPlanFor($processingLog);

                if ($plan === null) {
                    return ProcessingResult::failure(
                        processingId: $processingLog->processing_id,
                        message: 'This run has no extraction phase to resume from.',
                        errorCode: 'NO_EXTRACTION_PHASE'
                    );
                }

                // The store step refuses to overwrite a published video unless the run
                // says it is deliberately re-cutting one.
                $processingLog->markAsReExtraction();

                return $this->retryWithChainFromPlan($processingLog, $plan);
            });
        } catch (\Throwable $exception) {
            Log::error('Failed to re-extract sermon', [
                'processing_id' => $processingLog->processing_id,
                'error' => $exception->getMessage(),
            ]);

            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: 'Failed to re-extract sermon: '.$exception->getMessage(),
                errorCode: 'RE_EXTRACTION_FAILED'
            );
        }
    }

    /**
     * Run a callback with the historic staging context the run recorded, so its
     * retained artifacts resolve against the batch root they were written under.
     *
     * @param  \Closure(): ProcessingResult  $callback
     */
    private function withRecordedStagingContext(MediaProcessingLog $processingLog, \Closure $callback): ProcessingResult
    {
        $stagingContext = $processingLog->historicStagingContext();

        if ($stagingContext instanceof HistoricStagingContext) {
            return $this->stagingContextRegistry->within($stagingContext, $callback);
        }

        if ($processingLog->historicImportJobKey() !== null && ! $this->stagingContextRegistry->isActive()) {
            return ProcessingResult::failure(
                processingId: $processingLog->processing_id,
                message: 'This historic run records no staging context, so its retained artifacts cannot be resolved.',
                errorCode: 'HISTORIC_STAGING_CONTEXT_MISSING'
            );
        }

        return $callback();
    }

    /**
     * A historic run left non-terminal with no historic work in flight.
     *
     * The status alone cannot say this: a run whose first job failed inside the
     * `Queue::before` staging activation keeps reading `pending` or `processing`
     * for ever, and `isRetryable()` accepts neither, so recovery required
     * hand-forcing the row (2026-09-03). Requiring every historic queue to be
     * empty is deliberately conservative — a run genuinely mid-flight holds a
     * reserved job, so it can never be mistaken for stranded — and it means no
     * staleness threshold has to be guessed.
     *
     * The probe is resolved late rather than injected, for the same reason
     * {@see HistoricProcessingThroughput::historicQueueFor()} resolves its
     * registry late: it depends on the queue factory, and this orchestrator is
     * itself resolved while the queue manager is being built, so constructor
     * injection closes a container cycle that crashes the process outright
     * rather than raising anything catchable.
     */
    private function isStrandedHistoricRun(MediaProcessingLog $processingLog): bool
    {
        if ($processingLog->historic_import_operation_id === null) {
            return false;
        }

        $isOpen = in_array($processingLog->status, [
            ProcessingStatus::Pending,
            ProcessingStatus::Started,
            ProcessingStatus::Processing,
        ], true);

        return $isOpen && app(HistoricPassInFlightProbe::class)->inFlightCount() === 0;
    }

    /**
     * Resolve and dispatch the retry plan. Always runs with the run's historic staging
     * context active when it has one, so artifact keys resolve against the same batch root
     * the original attempt wrote them under.
     */
    private function runRetry(MediaProcessingLog $processingLog): ProcessingResult
    {
        if (! $processingLog->status->isRetryable() && ! $this->isStrandedHistoricRun($processingLog)) {
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
     * @return array{code: string, message: string}|null
     */
    private function historicTailValidationError(
        MediaProcessingLog $processingLog,
        HistoricImportOperation $operation,
        bool $checkStaleness = true,
        bool $allowFailedRetry = false,
    ): ?array {
        // A run that failed while holding its own tail claim is the retry case,
        // not a foreign failure: its previous tail attempt has already settled.
        $statusIsRecoverable = $processingLog->status === ProcessingStatus::Processing
            || ($allowFailedRetry && $processingLog->status === ProcessingStatus::Failed);

        if (! $statusIsRecoverable) {
            return [
                'code' => 'HISTORIC_TAIL_NOT_PROCESSING',
                'message' => 'Historic tail recovery requires a run that must still be processing.',
            ];
        }

        if ($processingLog->historic_import_operation_id === null
            || $processingLog->historicImportJobKey() === null) {
            return [
                'code' => 'HISTORIC_TAIL_NOT_HISTORIC',
                'message' => 'The processing run is not a historic run owned by an import operation.',
            ];
        }

        if ($processingLog->historic_import_operation_id !== $operation->id) {
            return [
                'code' => 'HISTORIC_TAIL_OPERATION_MISMATCH',
                'message' => 'The processing run does not belong to the named historic operation.',
            ];
        }

        $recordedOperationId = data_get(
            $processingLog->processing_metadata?->toArray(),
            'historic_import.operation_id',
        );

        if (! is_string($recordedOperationId) || $recordedOperationId !== $operation->operation_id) {
            return [
                'code' => 'HISTORIC_TAIL_METADATA_OPERATION_MISMATCH',
                'message' => 'The processing metadata operation identity does not match the named historic operation.',
            ];
        }

        $stagingContext = $processingLog->historicStagingContext();

        if ($stagingContext instanceof HistoricStagingContext
            && ! $this->stagingContextMatchesOperation($stagingContext, $operation)) {
            return [
                'code' => 'HISTORIC_TAIL_STAGING_CONTEXT_MISMATCH',
                'message' => 'The staging context manifest and plan hashes do not match the named historic operation.',
            ];
        }

        if ($operation->state === HistoricImportOperationState::Complete) {
            return [
                'code' => 'HISTORIC_TAIL_OPERATION_CLOSED',
                'message' => 'The named historic operation is already closed.',
            ];
        }

        if ($operation->accepted_deadline?->isPast() === true) {
            return [
                'code' => 'HISTORIC_TAIL_OPERATION_EXPIRED',
                'message' => 'The named historic operation deadline has elapsed.',
            ];
        }

        if ($checkStaleness && ! $this->historicTailIsStale($processingLog)) {
            return [
                'code' => 'HISTORIC_TAIL_NOT_STALE',
                'message' => 'The processing run is not stale; refusing to recover a fresh or live run.',
            ];
        }

        // AwaitHistoricSermonVideoStorage is the tail's first link and records
        // SermonSubmitted when it fails, so a run failed under its own tail claim
        // sits there rather than at the step it reached. Treating that as off-tail
        // would mean the gate's failure destroyed the state its own recovery needs.
        $tailSteps = $allowFailedRetry
            ? [...self::HistoricTailSteps, ProcessingStep::SermonSubmitted->value]
            : self::HistoricTailSteps;

        if (! in_array($this->canonicalTailStep($processingLog->current_step), $tailSteps, true)) {
            return [
                'code' => 'HISTORIC_TAIL_WRONG_STEP',
                'message' => 'The processing run is not at the promotion/cleanup tail.',
            ];
        }

        return null;
    }

    private function stagingContextMatchesOperation(
        HistoricStagingContext $stagingContext,
        HistoricImportOperation $operation,
    ): bool {
        $manifestHashes = $operation->manifest_hashes;
        $expectedManifestHash = $manifestHashes['historic_video'] ?? $manifestHashes['video'] ?? null;

        if (! is_string($expectedManifestHash) || $expectedManifestHash === '') {
            return false;
        }

        if ($operation->plan_hash === '') {
            return false;
        }

        return hash_equals($expectedManifestHash, $stagingContext->manifestHash)
            && hash_equals($operation->plan_hash, $stagingContext->planHash);
    }

    private function historicTailIsStale(MediaProcessingLog $processingLog): bool
    {
        $lastActivity = collect([$processingLog->started_at, $processingLog->updated_at])
            ->filter(static fn (mixed $timestamp): bool => $timestamp instanceof CarbonInterface)
            ->sortByDesc(static fn (CarbonInterface $timestamp): int => $timestamp->getTimestamp())
            ->first();

        if (! $lastActivity instanceof CarbonInterface) {
            return false;
        }

        $configuredThreshold = config(
            'media-processing.historic_import.tail_recovery_stale_after_seconds',
            self::DefaultHistoricTailRecoveryStaleAfterSeconds,
        );
        $threshold = is_numeric($configuredThreshold) ? (int) $configuredThreshold : 0;

        if ($threshold <= 0) {
            return false;
        }

        return $lastActivity->isBefore(now()->subSeconds($threshold));
    }

    private function canonicalTailStep(?string $step): ?string
    {
        return $step === null ? null : ProcessingStep::canonicalize($step);
    }

    private function queueForPipeline(string $pipeline): string
    {
        return match ($pipeline) {
            'video', 'video_auto_trim' => $this->videoQueue(),
            'livestream' => $this->livestreamQueue(),
            default => $this->audioQueue(),
        };
    }

    private function failureProfileForPipeline(string $pipeline): string
    {
        return match ($pipeline) {
            'video' => ProcessingRunFailureHandler::PROFILE_VIDEO,
            'video_auto_trim' => ProcessingRunFailureHandler::PROFILE_VIDEO_AUTO_TRIM,
            'livestream' => ProcessingRunFailureHandler::PROFILE_LIVESTREAM,
            default => ProcessingRunFailureHandler::PROFILE_AUDIO,
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
