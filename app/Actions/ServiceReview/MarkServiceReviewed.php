<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Models\ChurchService;
use App\Services\ChurchService\ChurchServiceReviewStateService;

class MarkServiceReviewed
{
    public function __construct(
        private readonly ChurchServiceReviewStateService $reviewStateService,
    ) {}

    /**
     * Clear the service-level review flag and record audit metadata.
     */
    public function execute(ChurchService $service, int $userId): void
    {
        $importMetadata = $service->import_metadata?->toArray() ?? [];
        $importMetadata['manual_review'] = [
            'reviewed_at' => now()->toIso8601String(),
            'reviewed_by_user_id' => $userId,
        ];
        unset($importMetadata['canonical_conflict']);
        $normalizedColumns = $this->reviewStateService->normalizedReviewColumns($importMetadata);

        $service->forceFill([
            'needs_review' => false,
            'review_reason' => null,
            'import_metadata' => $importMetadata,
            ...$normalizedColumns,
        ])->save();
    }
}
