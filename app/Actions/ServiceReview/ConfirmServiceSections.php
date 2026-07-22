<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Models\ChurchService;
use App\Queries\ServiceReviewDashboardQuery;

class ConfirmServiceSections
{
    public function __construct(
        private readonly ConfirmServiceSection $confirmSection,
        private readonly ServiceReviewDashboardQuery $query,
    ) {}

    /**
     * @return array{confirmed_count:int,skipped_reasons:array<string,int>}
     */
    public function execute(ChurchService $service, int $userId): array
    {
        $confirmedCount = 0;
        $skippedReasons = [];

        foreach ($this->query->reviewSectionsForService($service) as $section) {
            $skipReason = $this->query->confirmationSkipReason($section);

            if ($skipReason !== null) {
                $skippedReasons[$skipReason] = ($skippedReasons[$skipReason] ?? 0) + 1;

                continue;
            }

            $this->confirmSection->execute($section, $userId);
            $confirmedCount++;
        }

        return [
            'confirmed_count' => $confirmedCount,
            'skipped_reasons' => $skippedReasons,
        ];
    }
}
