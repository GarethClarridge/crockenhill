<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessingStep;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\SermonProcessingStepTransitions;
use App\Support\CancellationChecker;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class ProcessingJob
{
    private ?MediaProcessingRunTransitionService $processingRunTransitionService = null;

    private ?SermonProcessingStepTransitions $sermonProcessingStepTransitions = null;

    /**
     * The processing ID for this job chain
     */
    protected ?string $processingId = null;

    /**
     * Set the processing ID for step tracking
     */
    public function setProcessingId(string $processingId): self
    {
        $this->processingId = $processingId;

        return $this;
    }

    /**
     * Log that a processing step has started
     */
    protected function logStepStart(string $step, ?string $message = null): void
    {
        if (! $this->processingId) {
            Log::warning('Processing step start logged without processingId', [
                'step' => $step,
                'job_class' => get_class($this),
            ]);

            return;
        }

        $this->writeProcessingStep('started', $step, function () use ($message, $step): void {
            $this->processingStepTransitions()->markAsStarted((string) $this->processingId, ProcessingStep::canonicalize($step) ?? $step, $message);
        });
    }

    /**
     * Log that a processing step has completed successfully
     */
    protected function logStepComplete(string $step, ?string $message = null): void
    {
        if (! $this->processingId) {
            Log::warning('Processing step completion logged without processingId', [
                'step' => $step,
                'job_class' => get_class($this),
            ]);

            return;
        }

        $this->writeProcessingStep('completed', $step, function () use ($message, $step): void {
            $this->processingStepTransitions()->markAsCompleted((string) $this->processingId, ProcessingStep::canonicalize($step) ?? $step, $message);
        });
    }

    /**
     * Log that a processing step has failed
     */
    protected function logStepFailed(string $step, string $error): void
    {
        if (! $this->processingId) {
            Log::warning('Processing step failure logged without processingId', [
                'step' => $step,
                'error' => $error,
                'job_class' => get_class($this),
            ]);

            return;
        }

        $this->writeProcessingStep('failed', $step, function () use ($error, $step): void {
            $this->processingStepTransitions()->markAsFailed((string) $this->processingId, ProcessingStep::canonicalize($step) ?? $step, $error);
        });
    }

    /**
     * Log that a processing step was intentionally skipped.
     */
    protected function logStepSkipped(string $step, ?string $message = null): void
    {
        if (! $this->processingId) {
            Log::warning('Processing step skip logged without processingId', [
                'step' => $step,
                'job_class' => get_class($this),
            ]);

            return;
        }

        $this->writeProcessingStep('skipped', $step, function () use ($message, $step): void {
            $this->processingStepTransitions()->markAsSkipped((string) $this->processingId, ProcessingStep::canonicalize($step) ?? $step, $message);
        });
    }

    /**
     * @param  callable(): void  $write
     */
    private function writeProcessingStep(string $status, string $step, callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            Log::warning('Failed to write sermon processing step; continuing job', [
                'processing_id' => $this->processingId,
                'step' => $step,
                'status' => $status,
                'job_class' => get_class($this),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if processing has been cancelled
     */
    protected function isCancelled(): bool
    {
        if (! $this->processingId) {
            return false;
        }

        return CancellationChecker::isCancelled($this->processingId);
    }

    /**
     * Initialize step logging for this job
     */
    protected function initializeStepLogging(string $processingId): void
    {
        $this->processingId = $processingId;
    }

    /**
     * Persist queue/job/attempt correlation data onto the processing log.
     *
     * Call this once per job execution after initializeStepLogging().
     * Concrete jobs pass $this->job and $this->attempts() from InteractsWithQueue.
     */
    protected function captureQueueCorrelation(
        MediaProcessingLog $processingLog,
        ?Job $job,
        int $attempts
    ): void {
        try {
            $processingLog->update([
                'queue_name' => $job?->getQueue(),
                'job_id' => $job?->getJobId(),
                'attempt_count' => $attempts,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to capture queue correlation; continuing job', [
                'processing_id' => $processingLog->processing_id,
                'job_class' => get_class($this),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start step logging and capture queue correlation in one call.
     *
     * Prefer this over calling initializeStepLogging() and captureQueueCorrelation()
     * separately — it keeps correlation capture consistent across processing jobs.
     */
    protected function startProcessingJob(
        MediaProcessingLog $processingLog,
        ?Job $job,
        int $attempts
    ): void {
        $this->initializeStepLogging($processingLog->processing_id);
        $this->captureQueueCorrelation($processingLog, $job, $attempts);
    }

    /**
     * Refresh the processing log, initialize step logging, and check for cancellation.
     *
     * Returns true if the job should stop (log gone or cancelled). Reassigns the
     * passed-in log reference to the freshly-fetched model so callers always work
     * with up-to-date data after the call.
     *
     * Pass $job and $attempts (from InteractsWithQueue) to also capture queue
     * correlation data, equivalent to calling startProcessingJob() inline.
     */
    protected function refreshAndCheckCancellation(
        MediaProcessingLog &$processingLog,
        ?Job $job = null,
        int $attempts = 0
    ): bool {
        $refreshed = $processingLog->fresh();

        if (! $refreshed instanceof MediaProcessingLog) {
            return true;
        }

        $processingLog = $refreshed;

        if ($job !== null || $attempts > 0) {
            $this->startProcessingJob($processingLog, $job, $attempts);
        } else {
            $this->initializeStepLogging($processingLog->processing_id);
        }

        return $this->isCancelled();
    }

    /**
     * Get the processing ID from a sermon's processing log
     */
    protected function getProcessingIdFromSermon(int $sermonId): ?string
    {
        $sermon = Sermon::query()->find($sermonId);
        if (! $sermon) {
            return null;
        }

        /** @var MediaProcessingLog|null $processingLog */
        $processingLog = $sermon->processingLogs()->latest()->first();

        return $processingLog?->processing_id;
    }

    protected function processingRunTransitions(): MediaProcessingRunTransitionService
    {
        return $this->processingRunTransitionService ??= app(MediaProcessingRunTransitionService::class);
    }

    protected function processingStepTransitions(): SermonProcessingStepTransitions
    {
        return $this->sermonProcessingStepTransitions ??= app(SermonProcessingStepTransitions::class);
    }

    protected function markProcessingRunAsProcessing(MediaProcessingLog $processingLog, ?string $step = null): bool
    {
        return $this->processingRunTransitions()->markAsProcessing($processingLog, $step);
    }

    protected function markProcessingRunAsCompleted(
        MediaProcessingLog $processingLog,
        ?string $step = null,
        ?string $errorMessage = null
    ): bool {
        return $this->processingRunTransitions()->markAsCompleted($processingLog, $step, $errorMessage);
    }

    protected function markProcessingRunAsFailed(
        MediaProcessingLog $processingLog,
        string $errorMessage,
        ?string $step = null
    ): bool {
        return $this->processingRunTransitions()->markAsFailed($processingLog, $errorMessage, $step);
    }

    /**
     * @param  array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>  $speechSegments
     */
    protected function markProcessingRunForManualReview(
        MediaProcessingLog $processingLog,
        string $reasonCode,
        string $reasonMessage,
        array $speechSegments = []
    ): bool {
        return $this->processingRunTransitions()->markForManualReview(
            $processingLog,
            $reasonCode,
            $reasonMessage,
            $speechSegments
        );
    }

    protected function updateProcessingRunStep(MediaProcessingLog $processingLog, string $step): bool
    {
        return $this->processingRunTransitions()->updateStep($processingLog, $step);
    }

    final public function failed(Throwable $exception): void
    {
        $this->onJobFailure($exception);

        Log::error(get_class($this).' job failed permanently', [
            'processing_id' => $this->processingId,
            'error' => $exception->getMessage(),
            'attempts' => method_exists($this, 'attempts') ? $this->attempts() : null,
        ]);
    }

    protected function onJobFailure(Throwable $_exception): void {}
}
