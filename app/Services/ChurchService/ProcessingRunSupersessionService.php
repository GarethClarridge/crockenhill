<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Support\ChurchServiceRunMatcher;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * When more than one processing run projects sections onto the same service —
 * e.g. the same livestream uploaded twice — every moment gets duplicate,
 * overlapping sections. This picks the single authoritative run and marks the
 * rest superseded so review and timeline surfaces show one coherent structure.
 *
 * Best-run-wins (decision OD-2): the winner is ranked by high-confidence
 * coverage, then mean confidence, then section count, then recency — never by
 * run status, because a "failed" re-run can carry the better structure.
 */
class ProcessingRunSupersessionService
{
    public function __construct(
        private readonly ChurchServiceRunMatcher $runMatcher,
        private readonly ChurchServiceReviewSynchronizer $reviewSynchronizer,
    ) {}

    /**
     * Reconcile supersession across every segmentation run matched to a service.
     *
     * @return array{winner: MediaProcessingLog|null, superseded: list<int>}
     */
    public function reconcile(ChurchService $service, bool $execute = true): array
    {
        $runs = $this->matchedRunsWithSections($service);

        if ($runs->count() < 2) {
            return ['winner' => $runs->first(), 'superseded' => []];
        }

        $winner = $runs
            ->sortByDesc(fn (MediaProcessingLog $run): array => $this->score($run))
            ->first();

        if (! $winner instanceof MediaProcessingLog) {
            return ['winner' => null, 'superseded' => []];
        }

        $superseded = [];

        foreach ($runs as $run) {
            $isWinner = $run->is($winner);
            $targetSupersededAt = $isWinner ? null : now();
            $targetSupersededBy = $isWinner ? null : $winner->id;

            $changed = ($run->superseded_at === null) !== ($targetSupersededAt === null)
                || $run->superseded_by_processing_log_id !== $targetSupersededBy;

            if (! $isWinner) {
                $superseded[] = $run->id;
            }

            if ($execute && $changed) {
                $run->forceFill([
                    'superseded_at' => $targetSupersededAt,
                    'superseded_by_processing_log_id' => $targetSupersededBy,
                ])->saveQuietly();
            }
        }

        // Forward guard: once losers are superseded, the service-level review
        // latch and its triggers may be stale (they mirror the losing run's
        // sections). Recompute from the surviving sections so the service can't
        // stay flagged with nothing actionable. Any additive review roll-up that
        // follows in the projection path re-opens review if the winner needs it.
        if ($execute && $superseded !== []) {
            $this->reviewSynchronizer->reconcileServiceReview($service);
        }

        return ['winner' => $winner, 'superseded' => $superseded];
    }

    /**
     * @return EloquentCollection<int, MediaProcessingLog>
     */
    private function matchedRunsWithSections(ChurchService $service): EloquentCollection
    {
        $fallbackProcessingIds = $this->runMatcher->fallbackProcessingIdsForService($service);

        return MediaProcessingLog::query()
            ->segmentationPipeline()
            ->withCount('serviceSections')
            ->with('serviceSections:id,media_processing_log_id,confidence')
            ->tap(fn ($query) => $this->runMatcher->applyMatchClauses($query, $service, $fallbackProcessingIds))
            ->get()
            ->filter(fn (MediaProcessingLog $run): bool => $run->service_sections_count > 0)
            ->values();
    }

    /**
     * Higher is better, compared element by element.
     *
     * @return array{int, float, int, int}
     */
    private function score(MediaProcessingLog $run): array
    {
        $sections = $run->serviceSections;
        $count = $sections->count();

        $highConfidence = $sections
            ->filter(fn ($section): bool => (float) ($section->confidence ?? 0.0) >= ServiceSectionConfidence::HIGH_THRESHOLD)
            ->count();

        $meanConfidence = $count > 0
            ? (float) $sections->avg(fn ($section): float => (float) ($section->confidence ?? 0.0))
            : 0.0;

        return [
            $highConfidence,
            $meanConfidence,
            $count,
            (int) $run->id,
        ];
    }
}
