<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingStep;

/**
 * Service for managing the lifecycle and state transitions of individual
 * processing steps within a media processing run.
 *
 * Provides a structured API for recording the progress of discrete pipeline
 * operations (e.g. extraction, transcription, analysis) into the
 * `sermon_processing_steps` table. These records provide granular
 * observability beyond the high-level status of the `MediaProcessingLog`.
 */
class SermonProcessingStepTransitions
{
    /**
     * Record that a specific processing step has started.
     *
     * Creates a new step record or updates an existing one, setting its status
     * to 'started' and recording the current timestamp.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The identifier for the step being recorded
     * @param  string|null  $message  Optional context or details about the start
     * @return SermonProcessingStep The updated or created step record
     */
    public function markAsStarted(string $processingId, string $step, ?string $message = null): SermonProcessingStep
    {
        return SermonProcessingStep::query()->updateOrCreate(
            [
                'processing_id' => $processingId,
                'step' => $step,
            ],
            [
                'status' => ProcessingStatus::Started->value,
                'message' => $message,
                'started_at' => now(),
                'completed_at' => null,
            ]
        );
    }

    /**
     * Record that a specific processing step has completed successfully.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The identifier for the step being recorded
     * @param  string|null  $message  Optional completion details
     * @return SermonProcessingStep The updated step record
     */
    public function markAsCompleted(string $processingId, string $step, ?string $message = null): SermonProcessingStep
    {
        return $this->fillAndSave(
            $processingId,
            $step,
            status: ProcessingStatus::Completed,
            message: $message,
        );
    }

    /**
     * Record that a specific processing step has failed.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The identifier for the step being recorded
     * @param  string  $errorMessage  Descriptive error message for the failure
     * @return SermonProcessingStep The updated step record
     */
    public function markAsFailed(string $processingId, string $step, string $errorMessage): SermonProcessingStep
    {
        return $this->fillAndSave(
            $processingId,
            $step,
            status: ProcessingStatus::Failed,
            message: $errorMessage,
        );
    }

    /**
     * Record that a specific processing step was intentionally skipped.
     *
     * Used when a step is not required for a specific media type or when
     * cached results are already available.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The identifier for the step being recorded
     * @param  string|null  $message  Optional reason for skipping the step
     * @return SermonProcessingStep The updated step record
     */
    public function markAsSkipped(string $processingId, string $step, ?string $message = null): SermonProcessingStep
    {
        return $this->fillAndSave(
            $processingId,
            $step,
            status: ProcessingStatus::Skipped,
            message: $message,
        );
    }

    /**
     * Record that a specific processing step was cancelled.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  string  $step  The identifier for the step being recorded
     * @param  string|null  $message  Optional cancellation reason (defaults to 'Cancelled by user')
     * @return SermonProcessingStep The updated step record
     */
    public function markAsCancelled(string $processingId, string $step, ?string $message = null): SermonProcessingStep
    {
        $stepLog = SermonProcessingStep::query()->firstOrNew([
            'processing_id' => $processingId,
            'step' => $step,
        ]);

        $stepLog->fill([
            'status' => ProcessingStatus::Cancelled->value,
            'message' => $message ?? 'Cancelled by user',
            'started_at' => $stepLog->started_at ?? now(),
            'completed_at' => now(),
        ])->save();

        return $stepLog;
    }

    private function fillAndSave(
        string $processingId,
        string $step,
        ProcessingStatus $status,
        ?string $message,
    ): SermonProcessingStep {
        $stepLog = SermonProcessingStep::query()->firstOrNew([
            'processing_id' => $processingId,
            'step' => $step,
        ]);

        $stepLog->fill([
            'status' => $status->value,
            'message' => $message,
            'started_at' => $stepLog->started_at ?? now(),
            'completed_at' => now(),
        ])->save();

        return $stepLog;
    }
}
