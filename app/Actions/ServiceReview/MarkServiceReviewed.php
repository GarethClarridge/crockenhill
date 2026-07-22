<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Models\ChurchService;
use App\Queries\ServiceReviewDashboardQuery;
use App\Services\ChurchService\ChurchServiceReviewStateService;
use Illuminate\Support\Str;

class MarkServiceReviewed
{
    public function __construct(
        private readonly ChurchServiceReviewStateService $reviewStateService,
        private readonly ServiceReviewDashboardQuery $dashboardQuery,
    ) {}

    /**
     * Clear the service-level review flag when no section-level review remains.
     *
     * Returns a warning when section review work must be completed first.
     */
    public function execute(ChurchService $service, int $userId): ?string
    {
        $remainingSections = $this->dashboardQuery->manualReviewSectionsForService($service);

        if ($remainingSections->isNotEmpty()) {
            return sprintf(
                'This service still has %d %s needing attention. Confirm or resolve the section%s first.',
                $remainingSections->count(),
                Str::plural('section', $remainingSections->count()),
                $remainingSections->count() === 1 ? '' : 's'
            );
        }

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

        return null;
    }
}
