<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InboundEmailStatus;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Cache;

/**
 * Shared attention counts for the services hub strip and the members-home
 * badge. Every count is computed from the same code path that produces the
 * corresponding queue rows (contract C1) — in particular, flagged sections
 * use the dashboard query's seven-reason review-candidate predicate, not a
 * raw pending_approval count.
 *
 * The hub computes fresh via counts(); the high-traffic members home reads
 * the briefly cached copy via cached() — a stale badge is acceptable, a
 * wrong predicate is not.
 */
class AdminAttentionCounts
{
    private const CACHE_KEY = 'admin-attention-counts';

    private const CACHE_SECONDS = 60;

    public function __construct(
        private readonly ServiceReviewDashboardQuery $dashboardQuery,
    ) {}

    /**
     * @return array{
     *     pending_emails: int,
     *     awaiting_segment_runs: int,
     *     flagged_sections: int,
     *     pending_merges: int,
     *     services_needing_review: int
     * }
     */
    public function counts(): array
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            return [
                'pending_emails' => 0,
                'awaiting_segment_runs' => 0,
                'flagged_sections' => 0,
                'pending_merges' => 0,
                'services_needing_review' => 0,
            ];
        }

        return [
            'pending_emails' => InboundEmail::query()
                ->whereIn('status', [
                    InboundEmailStatus::Pending->value,
                    InboundEmailStatus::Failed->value,
                ])
                ->count(),
            'awaiting_segment_runs' => MediaProcessingLog::query()
                ->awaitingManualSermonReview()
                ->count(),
            'flagged_sections' => $this->flaggedSectionCount(),
            'pending_merges' => $this->dashboardQuery->pendingMergeCount(),
            /**
             * Current era only. Historic evidence-tier imports are unreviewed by design
             * (REV-D2) and belong to the per-round census, not to this badge —
             * see {@see ChurchService::scopeInCurrentEra()}.
             */
            'services_needing_review' => ChurchService::query()
                ->inCurrentEra()
                ->where('needs_review', true)
                ->count(),
        ];
    }

    /**
     * Briefly cached copy for the members-home badge call site only.
     *
     * @return array{
     *     pending_emails: int,
     *     awaiting_segment_runs: int,
     *     flagged_sections: int,
     *     pending_merges: int,
     *     services_needing_review: int
     * }
     */
    public function cached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->counts());
    }

    /**
     * @param  array{pending_emails: int, awaiting_segment_runs: int, flagged_sections: int, pending_merges: int, services_needing_review: int}|null  $counts
     */
    public function total(?array $counts = null): int
    {
        return array_sum($counts ?? $this->counts());
    }

    private function flaggedSectionCount(): int
    {
        if (! (bool) config('media-processing.section_publishing.enabled', true)) {
            return 0;
        }

        return $this->dashboardQuery->reviewCandidateSectionCount();
    }
}
