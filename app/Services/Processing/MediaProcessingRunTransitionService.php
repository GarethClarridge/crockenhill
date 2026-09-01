<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Data\ProcessingManualReviewMetadata;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingStep;
use App\Models\MediaProcessingLog;

/**
 * Service for managing state transitions of media processing runs.
 *
 * This service provides a centralized and consistent API for updating the
 * status and metadata of MediaProcessingLog records as they move through
 * the processing pipeline. It handles side effects such as timestamp
 * management and deduplication key lifecycle.
 */
class MediaProcessingRunTransitionService
{
    /**
     * Update arbitrary fields on a processing run, respecting cancellation state.
     *
     * Prevents updates to runs that have been cancelled, ensuring that
     * background jobs dispatched before cancellation do not overwrite the
     * cancelled status or associated error messages.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to update
     * @param  array<string, mixed>  $attributes  Key-value pairs to update
     * @return bool True if the update was successful
     */
    public function updateRunFields(MediaProcessingLog $processingLog, array $attributes): bool
    {
        if ($this->isCancelled($processingLog)) {
            return false;
        }

        return $processingLog->update($attributes);
    }

    /**
     * Transition a run to the 'processing' state.
     *
     * Records the start time if not already set and updates the current step.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to update
     * @param  string|null  $step  The name of the processing step being entered
     * @return bool True if the transition was successful
     */
    public function markAsProcessing(MediaProcessingLog $processingLog, ?string $step = null): bool
    {
        return $this->updateRunFields($processingLog, [
            'status' => ProcessingStatus::Processing,
            'current_step' => ProcessingStep::canonicalize($step),
            'started_at' => $processingLog->started_at ?? now(),
        ]);
    }

    /**
     * Reopen a settled run so a recoverable tail can be dispatched again.
     *
     * Distinct from {@see markAsProcessing()}, which advances a run through
     * steps it has not yet reached and so never has terminal fields to clear.
     * A run reopened from a terminal state keeps its old `completed_at` and
     * `error_message` unless they are cleared here, and then reads as
     * simultaneously active and failed in operator reporting and performance
     * evidence. `started_at` is deliberately preserved: it is the run's real
     * start, and duration measures are taken from it.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to reopen
     * @param  string|null  $step  The step the reopened run resumes at
     * @return bool True if the transition was successful
     */
    public function markAsReopened(MediaProcessingLog $processingLog, ?string $step = null): bool
    {
        return $this->updateRunFields($processingLog, [
            'status' => ProcessingStatus::Processing,
            'current_step' => ProcessingStep::canonicalize($step),
            'started_at' => $processingLog->started_at ?? now(),
            'completed_at' => null,
            'error_message' => null,
        ]);
    }

    /**
     * Transition a run to the 'completed' state.
     *
     * Marks the run as successful and records completion time. Historic runs
     * retain their manifest key so a restarted import recognises finished work.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to update
     * @param  string|null  $step  The final step name (defaults to 'completed')
     * @param  string|null  $errorMessage  Optional message or notes about completion
     * @return bool True if the transition was successful
     */
    public function markAsCompleted(
        MediaProcessingLog $processingLog,
        ?string $step = null,
        ?string $errorMessage = null
    ): bool {
        return $this->updateRunFields($processingLog, [
            'status' => ProcessingStatus::Completed,
            'current_step' => ProcessingStep::canonicalize($step ?? 'completed'),
            'completed_at' => now(),
            'error_message' => $errorMessage,
            'dedup_key' => $processingLog->historicImportJobKey(),
        ]);
    }

    /**
     * Transition a run to the 'failed' state.
     *
     * Records the error message and completion time. Ordinary uploads release
     * their deduplication key; historic runs retain their immutable manifest key.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to update
     * @param  string  $errorMessage  Descriptive error message for the failure
     * @param  string|null  $step  The step where the failure occurred
     * @return bool True if the transition was successful
     */
    public function markAsFailed(MediaProcessingLog $processingLog, string $errorMessage, ?string $step = null): bool
    {
        return $this->updateRunFields($processingLog, [
            'status' => ProcessingStatus::Failed,
            'current_step' => ProcessingStep::canonicalize($step ?? $processingLog->current_step),
            'error_message' => $errorMessage,
            'completed_at' => now(),
            'dedup_key' => $processingLog->historicImportJobKey(),
        ]);
    }

    /**
     * Transition a run to the 'cancelled' state.
     *
     * This terminal transition is allowed even if the run is already cancelled,
     * to ensure consistent state after user intervention. Clears the
     * deduplication key to allow an explicitly cancelled source to be re-dispatched.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to update
     * @param  string|null  $message  Optional cancellation reason
     * @return bool True if the update was successful
     */
    public function markAsCancelled(MediaProcessingLog $processingLog, ?string $message = null): bool
    {
        // Cancellation is the terminal transition that is allowed to override any
        // non-cancelled state, so this deliberately skips the cancelled-run guard.
        return $processingLog->update([
            'status' => ProcessingStatus::Cancelled,
            'current_step' => 'cancelled',
            'error_message' => $message ?? 'Processing cancelled by user',
            'completed_at' => now(),
            'dedup_key' => null,
        ]);
    }

    /**
     * Flag a run as requiring manual administrative review.
     *
     * Used when the automated pipeline (e.g. livestream segmentation) cannot
     * confidently identify the sermon boundaries. Sets the state to 'failed'
     * with a specific step and reason metadata.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to flag
     * @param  string  $reasonCode  Machine-readable code for the review reason
     * @param  string  $reasonMessage  Human-readable explanation of why review is needed
     * @param  array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>  $speechSegments  Candidate segments for review
     * @return bool True if the transition was successful
     */
    public function markForManualReview(
        MediaProcessingLog $processingLog,
        string $reasonCode,
        string $reasonMessage,
        array $speechSegments = []
    ): bool {
        if ($this->isCancelled($processingLog)) {
            return false;
        }

        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $metadata['manual_review'] = (new ProcessingManualReviewMetadata(
            status: 'required',
            reasonCode: $reasonCode,
            reasonMessage: $reasonMessage,
            flaggedAt: now()->toIso8601String(),
            speechSegments: array_values($speechSegments),
        ))->toArray();

        return $processingLog->update([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'error_message' => $reasonMessage,
            'processing_metadata' => $metadata,
            'dedup_key' => $processingLog->historicImportJobKey(),
        ]);
    }

    /**
     * Confirm a specific sermon segment for a run awaiting manual review.
     *
     * Transitions the run back to 'pending' state with the confirmed segment
     * ID stored in metadata, allowing the orchestrator to resume processing.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to confirm
     * @param  int  $segmentId  The ID of the confirmed LivestreamSegment
     * @param  int|null  $userId  The ID of the user who performed the confirmation
     * @return bool True if the transition was successful
     */
    public function confirmSermonSegment(MediaProcessingLog $processingLog, int $segmentId, ?int $userId): bool
    {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $manualReview = $processingLog->processing_metadata->manualReview
            ?? ProcessingManualReviewMetadata::fromArray($processingLog->manualReviewMetadata());
        $speechSegments = $manualReview instanceof ProcessingManualReviewMetadata
            ? $manualReview->speechSegments
            : [];
        $metadata['manual_review'] = (new ProcessingManualReviewMetadata(
            status: 'confirmed',
            reasonCode: $manualReview?->reasonCode,
            reasonMessage: $manualReview?->reasonMessage,
            flaggedAt: $manualReview?->flaggedAt,
            speechSegments: $speechSegments,
            confirmedSegmentId: $segmentId,
            confirmedByUserId: $userId,
            confirmedAt: now()->toIso8601String(),
        ))->toArray();

        return $processingLog->update([
            'status' => ProcessingStatus::Pending,
            'current_step' => 'manual_review_confirmed',
            'error_message' => null,
            'processing_metadata' => $metadata,
        ]);
    }

    /**
     * Resolve a run awaiting manual sermon review without a human segment
     * selection, so the extraction resolver falls through to its detected
     * high-confidence sermon *section* (the correct boundaries) rather than a
     * coarse whole-recording speech segment.
     *
     * Used to reconcile runs left paused by a superseded policy: the structure
     * path already produced an auto-extractable sermon, making the segment
     * selection redundant. No confirmedSegmentId is set — that is the whole
     * point, so SermonExtractionPlanResolver prefers the sermon section.
     *
     * @return bool True if the transition was successful
     */
    public function autoResolveManualReviewFromStructure(MediaProcessingLog $processingLog): bool
    {
        $manualReview = $processingLog->processing_metadata->manualReview
            ?? ProcessingManualReviewMetadata::fromArray($processingLog->manualReviewMetadata());
        $speechSegments = $manualReview instanceof ProcessingManualReviewMetadata
            ? $manualReview->speechSegments
            : [];

        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $metadata['manual_review'] = (new ProcessingManualReviewMetadata(
            status: 'auto_resolved',
            reasonCode: $manualReview?->reasonCode,
            reasonMessage: $manualReview?->reasonMessage,
            flaggedAt: $manualReview?->flaggedAt,
            speechSegments: $speechSegments,
            confirmedSegmentId: null,
            confirmedByUserId: null,
            confirmedAt: now()->toIso8601String(),
        ))->toArray();

        return $processingLog->update([
            'status' => ProcessingStatus::Pending,
            'current_step' => 'manual_review_confirmed',
            'error_message' => null,
            'processing_metadata' => $metadata,
        ]);
    }

    /**
     * Update the current processing step without changing the overall status.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to update
     * @param  string  $step  The new step identifier
     * @return bool True if the update was successful
     */
    public function updateStep(MediaProcessingLog $processingLog, string $step): bool
    {
        return $this->updateRunFields($processingLog, ['current_step' => ProcessingStep::canonicalize($step)]);
    }

    /**
     * Reset a run's status and timestamps for a retry attempt.
     *
     * Clears error messages and completion times, transitions status to 'pending',
     * and restores the run's stable deduplication key to prevent concurrent duplicates.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to reset
     * @return bool True if the reset was successful
     */
    public function resetForRetry(MediaProcessingLog $processingLog): bool
    {
        return $processingLog->update([
            'status' => ProcessingStatus::Pending,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
            'dedup_key' => $processingLog->historicImportJobKey() ?? $processingLog->buildDedupKey(),
        ]);
    }

    private function isCancelled(MediaProcessingLog $processingLog): bool
    {
        $freshLog = $processingLog->fresh();

        return $freshLog?->isCancelled() ?? false;
    }
}
