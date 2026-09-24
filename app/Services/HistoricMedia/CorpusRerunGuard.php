<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Support\RepositoryCommit;

/**
 * The batch guards every corpus re-run dispatch shares, whichever tier it is (plan §4.0).
 *
 * - the run is a member of the batch's snapshot, so the diff report has its before-state;
 * - the snapshot was taken on the commit now running, so the evidence binds one commit;
 * - the run was not already re-run on this commit, by either tier, so a canary run is not run
 *   again by its batch, an interrupted batch resumes without repeating work, and no run is
 *   re-transcribed and re-detected on the same commit;
 * - the run is completed and not excluded (the eligible membership);
 * - the run has not changed since the snapshot, so the diff reads the re-run's change alone.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class CorpusRerunGuard
{
    public function __construct(
        private readonly HistoricRerunState $state,
        private readonly HistoricRerunDiff $diff,
    ) {}

    public function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
    {
        if (! $snapshot->holds($run->id)) {
            return 'run is not a member of this snapshot';
        }

        $commit = RepositoryCommit::current();

        if ($snapshot->gitCommit === null || $snapshot->gitCommit !== $commit) {
            return sprintf('snapshot was taken on %s but %s is running; take a new snapshot on the frozen commit', $snapshot->gitCommit ?? 'an unknown commit', $commit ?? 'an unknown commit');
        }

        foreach ($run->corpusRerunStamps() as $stamp) {
            if (($stamp['git_commit'] ?? null) === $commit) {
                return sprintf('already re-run on this commit at %s', (string) ($stamp['dispatched_at'] ?? 'an unrecorded time'));
            }
        }

        if ($run->status !== ProcessingStatus::Completed) {
            return sprintf('run is %s, not completed', $run->status->value);
        }

        if ($run->isExcluded()) {
            return 'run is excluded';
        }

        if ($this->diff->compare($snapshot->runs[$run->id], $this->state->capture($run))['changes'] !== []) {
            return 'run has changed since the snapshot; take a new snapshot so the diff reads this re-run alone';
        }

        return null;
    }
}
