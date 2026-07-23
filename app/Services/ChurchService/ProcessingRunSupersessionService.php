<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionSongMatchType;
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
 * Best-run-wins (decision OD-2, revised): the winner is ranked by
 * transcript-confirmed song count, then high-confidence coverage, then completed
 * status, then mean confidence, section count, and recency.
 *
 * Confirmed song matches lead the ordering because they are grounded evidence —
 * the section's transcript matched the catalogue lyrics/title above the writeback
 * threshold (see MatchSongsFromTranscript) — whereas the confidence terms are the
 * segmentation classifier's self-assessment of section *type*, a softer prior.
 * A run that only *inferred* its songs by projecting the plan has verified
 * nothing, so it must not supersede a run that confirmed them (service 785).
 *
 * Completed status sits *below* the grounded evidence terms, never above them:
 * it only breaks ties once song evidence and high-confidence coverage are equal,
 * so it can never veto a failed re-run that carries genuinely better structure
 * (OD-2's original point). When everything grounded is equal, the run that
 * actually finished the pipeline is the record worth keeping.
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
            ->with('serviceSections:id,media_processing_log_id,confidence,song_match_type')
            ->tap(fn ($query) => $this->runMatcher->applyMatchClauses($query, $service, $fallbackProcessingIds))
            ->get()
            ->filter(fn (MediaProcessingLog $run): bool => $run->service_sections_count > 0)
            ->values();
    }

    /**
     * Higher is better, compared element by element.
     *
     * @return array{int, int, int, float, int, int}
     */
    private function score(MediaProcessingLog $run): array
    {
        $sections = $run->serviceSections;
        $count = $sections->count();

        $confirmedSongs = $sections
            ->filter(fn ($section): bool => $section->song_match_type === ServiceSectionSongMatchType::Confirmed)
            ->count();

        $highConfidence = $sections
            ->filter(fn ($section): bool => (float) ($section->confidence ?? 0.0) >= ServiceSectionConfidence::HIGH_THRESHOLD)
            ->count();

        $meanConfidence = $count > 0
            ? (float) $sections->avg(fn ($section): float => (float) ($section->confidence ?? 0.0))
            : 0.0;

        return [
            $confirmedSongs,
            $highConfidence,
            $run->isComplete() ? 1 : 0,
            $meanConfidence,
            $count,
            (int) $run->id,
        ];
    }
}
