<?php

namespace App\Jobs;

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
                'status' => 'started',
                'message' => $message,
                'started_at' => now(),
                'completed_at' => null,
            ]
        );

        Log::info('Processing step started', [
            'processing_id' => $this->processingId,
            'step' => $step,
            'message' => $message,
        ]);
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

        SermonProcessingStep::where('processing_id', $this->processingId)
            ->where('step', $step)
            ->update([
                'status' => 'completed',
                'message' => $message,
                'completed_at' => now(),
            ]);

        Log::info('Processing step completed', [
            'processing_id' => $this->processingId,
            'step' => $step,
            'message' => $message,
        ]);
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

        SermonProcessingStep::where('processing_id', $this->processingId)
            ->where('step', $step)
            ->update([
                'status' => 'failed',
                'message' => $error,
                'completed_at' => now(),
            ]);

        Log::error('Processing step failed', [
            'processing_id' => $this->processingId,
            'step' => $step,
            'error' => $error,
        ]);
    }

    /**
     * Check if processing has been cancelled
     */
    protected function isCancelled(): bool
    {
        if (! $this->processingId) {
            return false;
        }

        $cancelledSteps = SermonProcessingStep::where('processing_id', $this->processingId)
            ->where('status', 'cancelled')
            ->count();

        return $cancelledSteps > 0;
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

        /** @var \App\Models\SermonProcessingLog|null $processingLog */
        $processingLog = $sermon->processingLogs()->latest()->first();

        return $processingLog?->processing_id;
    }
}
