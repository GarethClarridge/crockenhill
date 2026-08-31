<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Enums\ProcessingStep;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupTemporaryFiles implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * How many times a historic cleanup may stand itself back down while work it
     * would orphan is still in flight. Twelve deferrals of
     * {@see self::HISTORIC_DEFERRAL_DELAY_SECONDS} give an hour, comfortably past
     * a retrying speaker or storage job, after which the run is failed rather
     * than left sitting in `processing` with nothing left to advance it.
     */
    private const MAX_HISTORIC_DEFERRALS = 12;

    private const HISTORIC_DEFERRAL_DELAY_SECONDS = 300;

    /**
     * @param  int  $historicDeferrals  How many times this cleanup has already
     *                                  stood down for unsettled historic work.
     *                                  Only a deferred successor sets this.
     */
    public function __construct(
        private MediaProcessingLog $processingLog,
        private int $historicDeferrals = 0,
    ) {}

    public function handle(
        VideoStorageService $storageService,
        ?MediaProcessingRunTransitionService $processingRunTransitions = null
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);

        $processingLog = $this->processingLog->fresh();
        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;

        // Snapshot cancellation state before cleanup runs — cleanup proceeds either way,
        // but markAsCompleted() must not revive a cancelled run.
        $isCancelled = $this->processingLog->isCancelled();

        try {
            Log::info('Starting temporary file cleanup', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
            ]);

            $unsettledWork = $this->unsettledHistoricMediaWork();
            if ($unsettledWork !== null) {
                $this->refuseCleanup($unsettledWork, $processingRunTransitions);

                return;
            }

            $tempFiles = $this->processingLog->temporaryFilePaths();

            Log::info('Cleaning up temporary files', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
                'file_count' => count($tempFiles),
                'files' => $tempFiles,
            ]);

            $storageService->cleanupTemporaryFiles($tempFiles);

            Log::info('Temporary file cleanup completed', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            // Do not mark as completed when the run was cancelled — the CANCELLED
            // status must be preserved so nothing can revive it after cleanup.
            if (! $isCancelled) {
                // Preserve a non-fatal notification failure signal while still
                // marking the run as completed after cleanup.
                $processingRunTransitions->markAsCompleted(
                    $this->processingLog,
                    step: $this->completionStep(),
                    errorMessage: $this->completionErrorMessage()
                );
            }

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup some temporary files', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            // Still mark as complete even if cleanup had issues, but not for cancelled runs.
            if (! $isCancelled) {
                $processingRunTransitions->markAsCompleted(
                    $this->processingLog,
                    step: $this->completionStep(),
                    errorMessage: $this->completionErrorMessage()
                );
            }
        }
    }

    private function completionStep(): string
    {
        $step = $this->processingLog->current_step;

        if (in_array($step, ['notification_failed', 'notification_failed_permanently'], true)) {
            return $step;
        }

        return 'completed';
    }

    private function completionErrorMessage(): ?string
    {
        if (in_array($this->processingLog->current_step, ['notification_failed', 'notification_failed_permanently'], true)) {
            return $this->processingLog->error_message;
        }

        return null;
    }

    /**
     * Refusing to delete is only half of a truthful refusal. A bare return would
     * leave the run sitting in `processing` with no error and nothing left in the
     * chain to advance it -- the same stranded tail this guard exists to prevent.
     * So in-flight work earns a bounded deferral, and work that can no longer
     * settle fails the run closed where readiness and tail recovery can see it.
     *
     * @param  array{description:string,terminal:bool}  $unsettledWork
     */
    private function refuseCleanup(
        array $unsettledWork,
        MediaProcessingRunTransitionService $processingRunTransitions,
    ): void {
        $canDefer = ! $unsettledWork['terminal']
            && $this->historicDeferrals < self::MAX_HISTORIC_DEFERRALS;

        Log::warning('Refusing temporary file cleanup while historic media work is unsettled', [
            'processing_id' => $this->processingLog->processing_id,
            'work' => $unsettledWork['description'],
            'deferrals' => $this->historicDeferrals,
            'deferring' => $canDefer,
        ]);

        if ($canDefer) {
            dispatch(new self($this->processingLog, $this->historicDeferrals + 1))
                ->delay(self::HISTORIC_DEFERRAL_DELAY_SECONDS);

            return;
        }

        $processingRunTransitions->markAsFailed(
            $this->processingLog,
            'Refused to clean up temporary files: historic media work never settled ('
            .$unsettledWork['description'].').',
            ProcessingStep::SermonSubmitted->value,
        );
    }

    /**
     * Work that still references this run's working copies. `terminal` marks work
     * that can no longer settle on its own, so waiting for it would never end.
     *
     * @return array{description:string,terminal:bool}|null
     */
    private function unsettledHistoricMediaWork(): ?array
    {
        if ($this->processingLog->historic_import_operation_id === null) {
            return null;
        }

        $nestedStorage = HistoricImportNestedJob::query()
            ->where('historic_import_operation_id', $this->processingLog->historic_import_operation_id)
            ->where('media_processing_log_id', $this->processingLog->id)
            ->where('job_key', StoreSermonVideo::nestedJobKey($this->processingLog->processing_id))
            ->where('job_type', StoreSermonVideo::class)
            ->whereIn('state', ['queued', 'running', 'retryable', 'failed'])
            ->first();

        if ($nestedStorage instanceof HistoricImportNestedJob) {
            return [
                'description' => $nestedStorage->job_key.' (state: '.$nestedStorage->state.')',
                'terminal' => $nestedStorage->state === 'failed',
            ];
        }

        /**
         * Only work that is still running can be orphaned by deleting its inputs.
         * A failed step has released them: IdentifySpeaker in particular treats a
         * deterministic failure as non-blocking on purpose -- it records the step
         * failed, falls back to `Visiting Speaker` and lets the chain continue --
         * so reading that row as unsettled would turn a tolerated soft failure
         * into a hard run failure and strand the working copies it was meant to
         * protect.
         */
        $activeStep = SermonProcessingStep::query()
            ->where('processing_id', $this->processingLog->processing_id)
            ->whereIn('step', ['assessing_video_quality', 'identifying_speaker'])
            ->whereIn('status', [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Started->value,
                ProcessingStatus::Processing->value,
            ])
            ->first();

        if ($activeStep instanceof SermonProcessingStep) {
            return [
                'description' => $activeStep->step.' (state: '.$activeStep->status->value.')',
                'terminal' => false,
            ];
        }

        return null;
    }
}
