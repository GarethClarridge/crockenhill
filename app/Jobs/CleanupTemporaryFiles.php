<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessingStep;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\HistoricWorkingCopyReachability;
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
        ?MediaProcessingRunTransitionService $processingRunTransitions = null,
        ?HistoricWorkingCopyReachability $workingCopyReachability = null,
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);
        $workingCopyReachability ??= app(HistoricWorkingCopyReachability::class);

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

            $unsettledWork = $workingCopyReachability->unsettledWork($this->processingLog);
            if ($unsettledWork !== null) {
                $this->refuseCleanup($unsettledWork, $processingRunTransitions);

                return;
            }

            $tempFiles = $this->processingLog->temporaryFilePaths();
            $retentionReasons = $this->processingLog->reviewSourceRetentionReasons();
            $sourcePath = $this->processingLog->source_file_path;

            if ($retentionReasons !== [] && is_string($sourcePath) && $sourcePath !== '') {
                $tempFiles = array_values(array_filter(
                    $tempFiles,
                    static fn (string $path): bool => $path !== $sourcePath,
                ));

                Log::info('Retaining historic review source until its review obligations resolve', [
                    'processing_id' => $this->processingLog->processing_id,
                    'source_file_path' => $sourcePath,
                    'reasons' => $retentionReasons,
                ]);
            }

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
            if (! $isCancelled && ! $this->isManualReviewRun()) {
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
            if (! $isCancelled && ! $this->isManualReviewRun()) {
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

    private function isManualReviewRun(): bool
    {
        return $this->processingLog->isFailed()
            && $this->processingLog->current_step === 'manual_review_required';
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
}
