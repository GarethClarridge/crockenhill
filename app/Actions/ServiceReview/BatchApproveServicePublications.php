<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Actions\Publication\ApproveSectionForPublication;
use App\Models\ChurchService;
use App\Queries\ServiceReviewDashboardQuery;
use Illuminate\Support\Str;

class BatchApproveServicePublications
{
    public function __construct(
        private readonly ApproveSectionForPublication $approveSectionForPublication,
        private readonly ServiceReviewDashboardQuery $query,
    ) {}

    /**
     * @return array{approved_count:int,skipped_reasons:array<string,int>}
     */
    public function execute(ChurchService $service, int $userId): array
    {
        $pendingSections = $this->query->pendingPublicationSectionsForService($service);

        $auditMetadata = [
            'batch_id' => (string) Str::uuid(),
            'approved_at' => now()->toIso8601String(),
            'approved_by_user_id' => $userId,
            'source' => 'service_review_dashboard',
            'action' => 'approve_pending_publications',
            'church_service_id' => $service->id,
        ];

        $approvedCount = 0;
        $skippedReasons = [];

        foreach ($pendingSections as $section) {
            $skipReason = $this->query->batchApprovalSkipReason($section);

            if ($skipReason !== null) {
                $skippedReasons[$skipReason] = ($skippedReasons[$skipReason] ?? 0) + 1;

                continue;
            }

            $error = $this->approveSectionForPublication->execute($section, $auditMetadata);
            if ($error !== null) {
                $normalizedError = $this->normalizeApprovalError($error);
                $skippedReasons[$normalizedError] = ($skippedReasons[$normalizedError] ?? 0) + 1;

                continue;
            }

            $approvedCount++;
        }

        return [
            'approved_count' => $approvedCount,
            'skipped_reasons' => $skippedReasons,
        ];
    }

    private function normalizeApprovalError(string $message): string
    {
        return match ($message) {
            'Section media is missing. Reclassify and prepare candidates again.' => 'missing extracted media',
            "Choose a speaker for this children's talk before approving publication." => 'speaker review required',
            'This section cannot be approved in its current state.' => 'invalid approval state',
            default => Str::of($message)->trim()->lower()->toString(),
        };
    }
}
