<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingStep;
use Illuminate\Support\Facades\Log;

abstract class ProcessingJob
{
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

        SermonProcessingStep::updateOrCreate(
            [
                'processing_id' => $this->processingId,
                'step' => $step,
            ],
            [
                'status' => ProcessingStatus::STARTED->value,
                'message' => $message,
                'started_at' => now(),
                'completed_at' => null,
            ]
        );

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

        $stepLog = SermonProcessingStep::firstOrNew([
            'processing_id' => $this->processingId,
            'step' => $step,
        ]);

        $stepLog->fill([
            'status' => ProcessingStatus::COMPLETED->value,
            'message' => $message,
            'started_at' => $stepLog->started_at ?? now(),
            'completed_at' => now(),
        ])->save();

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

        $stepLog = SermonProcessingStep::firstOrNew([
            'processing_id' => $this->processingId,
            'step' => $step,
        ]);

        $stepLog->fill([
            'status' => ProcessingStatus::FAILED->value,
            'message' => $error,
            'started_at' => $stepLog->started_at ?? now(),
            'completed_at' => now(),
        ])->save();

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

        $stepLog = SermonProcessingStep::firstOrNew([
            'processing_id' => $this->processingId,
            'step' => $step,
        ]);

        $stepLog->fill([
            'status' => ProcessingStatus::SKIPPED->value,
            'message' => $message,
            'started_at' => $stepLog->started_at ?? now(),
            'completed_at' => now(),
        ])->save();

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
            ->where('status', ProcessingStatus::CANCELLED->value)
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
}
