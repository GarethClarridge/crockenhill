<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\MediaProcessingRunTransitionService;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class ProcessingJob
{
    private ?MediaProcessingRunTransitionService $processingRunTransitionService = null;

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
            SermonProcessingStep::updateOrCreate(
                [
                    'processing_id' => $this->processingId,
                    'step' => $step,
                ],
                [
                    'status' => ProcessingStatus::Started->value,
                    'message' => $message,
                    'started_at' => now(),
                    'completed_at' => null,
                ]
            );
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
            $stepLog = SermonProcessingStep::firstOrNew([
                'processing_id' => $this->processingId,
                'step' => $step,
            ]);

            $stepLog->fill([
                'status' => ProcessingStatus::Completed->value,
                'message' => $message,
                'started_at' => $stepLog->started_at ?? now(),
                'completed_at' => now(),
            ])->save();
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
            $stepLog = SermonProcessingStep::firstOrNew([
                'processing_id' => $this->processingId,
                'step' => $step,
            ]);

            $stepLog->fill([
                'status' => ProcessingStatus::Failed->value,
                'message' => $error,
                'started_at' => $stepLog->started_at ?? now(),
                'completed_at' => now(),
            ])->save();
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
            $stepLog = SermonProcessingStep::firstOrNew([
                'processing_id' => $this->processingId,
                'step' => $step,
            ]);

            $stepLog->fill([
                'status' => ProcessingStatus::Skipped->value,
                'message' => $message,
                'started_at' => $stepLog->started_at ?? now(),
                'completed_at' => now(),
            ])->save();
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

        // Check if any processing steps have been cancelled
        $cancelledSteps = SermonProcessingStep::where('processing_id', $this->processingId)
            ->where('status', ProcessingStatus::Cancelled->value)
            ->count();

        if ($cancelledSteps > 0) {
            return true;
        }

        // Also check the main processing log for CANCELLED status
        $log = \App\Models\MediaProcessingLog::where('processing_id', $this->processingId)->first();

        return $log?->isCancelled() ?? false;
    }

    /**
     * Initialize step logging for this job
     */
    protected function initializeStepLogging(string $processingId): void
    {
        $this->processingId = $processingId;
    }

    /**
     * Get the processing ID from a sermon's processing log
     */
    protected function getProcessingIdFromSermon(int $sermonId): ?string
    {
        $sermon = \App\Models\Sermon::find($sermonId);
        if (! $sermon) {
            return null;
        }

        /** @var \App\Models\MediaProcessingLog|null $processingLog */
        $processingLog = $sermon->processingLogs()->latest()->first();

        return $processingLog?->processing_id;
    }

    protected function processingRunTransitions(): MediaProcessingRunTransitionService
    {
        return $this->processingRunTransitionService ??= app(MediaProcessingRunTransitionService::class);
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
}
