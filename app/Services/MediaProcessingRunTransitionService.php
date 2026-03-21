<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ProcessingManualReviewMetadata;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;

class MediaProcessingRunTransitionService
{
    public function markAsProcessing(MediaProcessingLog $processingLog, ?string $step = null): bool
    {
        if ($this->isCancelled($processingLog)) {
            return false;
        }

        return $processingLog->update([
            'status' => ProcessingStatus::PROCESSING,
            'current_step' => $step,
            'started_at' => $processingLog->started_at ?? now(),
        ]);
    }

    public function markAsCompleted(
        MediaProcessingLog $processingLog,
        ?string $step = null,
        ?string $errorMessage = null
    ): bool {
        if ($this->isCancelled($processingLog)) {
            return false;
        }

        return $processingLog->update([
            'status' => ProcessingStatus::COMPLETED,
            'current_step' => $step ?? 'completed',
            'completed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    public function markAsFailed(MediaProcessingLog $processingLog, string $errorMessage, ?string $step = null): bool
    {
        if ($this->isCancelled($processingLog)) {
            return false;
        }

        return $processingLog->update([
            'status' => ProcessingStatus::FAILED,
            'current_step' => $step ?? $processingLog->current_step,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function markAsCancelled(MediaProcessingLog $processingLog, ?string $message = null): bool
    {
        // Cancellation is the terminal transition that is allowed to override any
        // non-cancelled state, so this deliberately skips the cancelled-run guard.
        return $processingLog->update([
            'status' => ProcessingStatus::CANCELLED,
            'current_step' => 'cancelled',
            'error_message' => $message ?? 'Processing cancelled by user',
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>  $speechSegments
     */
    public function markForManualReview(
        MediaProcessingLog $processingLog,
        string $reasonCode,
        string $reasonMessage,
        array $speechSegments = []
    ): bool {
        $metadata = $processingLog->processingMetadataData()->toArray();
        $metadata['manual_review'] = (new ProcessingManualReviewMetadata(
            status: 'required',
            reasonCode: $reasonCode,
            reasonMessage: $reasonMessage,
            flaggedAt: now()->toIso8601String(),
            speechSegments: array_values($speechSegments),
        ))->toArray();

        return $processingLog->update([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'manual_review_required',
            'error_message' => $reasonMessage,
            'processing_metadata' => $metadata,
        ]);
    }

    public function confirmSermonSegment(MediaProcessingLog $processingLog, int $segmentId, int $userId): bool
    {
        $metadata = $processingLog->processingMetadataData()->toArray();
        $manualReview = $processingLog->manualReviewData()
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
            'status' => ProcessingStatus::PENDING,
            'current_step' => 'manual_review_confirmed',
            'error_message' => null,
            'processing_metadata' => $metadata,
        ]);
    }

    public function updateStep(MediaProcessingLog $processingLog, string $step): bool
    {
        return $processingLog->update(['current_step' => $step]);
    }

    public function resetForRetry(MediaProcessingLog $processingLog): bool
    {
        return $processingLog->update([
            'status' => ProcessingStatus::PENDING,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    private function isCancelled(MediaProcessingLog $processingLog): bool
    {
        $freshLog = $processingLog->fresh();

        return $freshLog?->isCancelled() ?? false;
    }
}
