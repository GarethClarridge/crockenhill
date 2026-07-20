<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceImportMetadata;
use App\Enums\ChurchServiceReviewState;

class ChurchServiceReviewStateService
{
    /**
     * @param  array<string, mixed>  $importMetadata
     * @return array{
     *     review_state: string,
     *     manual_reviewed_at: string|null,
     *     manual_reviewed_by_user_id: int|null,
     *     manual_review_reopened_at: string|null,
     *     manual_review_reopened_by_source: string|null
     * }
     */
    public function normalizedReviewColumns(array $importMetadata): array
    {
        $metadata = ChurchServiceImportMetadata::fromArray($importMetadata);

        $reviewState = match (true) {
            is_string($metadata->manualReview?->reopenedAt) => ChurchServiceReviewState::Reopened,
            is_string($metadata->manualReview?->reviewedAt) => ChurchServiceReviewState::Reviewed,
            default => ChurchServiceReviewState::NotReviewed,
        };

        return [
            'review_state' => $reviewState->value,
            'manual_reviewed_at' => $metadata->manualReview?->reviewedAt,
            'manual_reviewed_by_user_id' => $metadata->manualReview?->reviewedByUserId,
            'manual_review_reopened_at' => $metadata->manualReview?->reopenedAt,
            'manual_review_reopened_by_source' => $metadata->manualReview?->reopenedBySource,
        ];
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     * @param  array<string, mixed>  $canonicalConflict
     * @return array<string, mixed>
     */
    public function withCanonicalConflictHistory(array $importMetadata, array $canonicalConflict): array
    {
        $metadata = ChurchServiceImportMetadata::fromArray($importMetadata);
        $history = $metadata->canonicalConflictHistory;
        $history[] = $canonicalConflict;

        $data = $metadata->toArray();
        $data['canonical_conflict_history'] = $history;
        unset($data['canonical_conflict']);

        return $data;
    }
}
