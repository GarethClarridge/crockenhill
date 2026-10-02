<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Actions\HoldSectionForContentReview;
use App\Actions\RecomposeForCorpusRerun;
use App\Actions\RedetectForCorpusRerun;
use App\Actions\RetranscribeForCorpusRerun;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\CodeRevision;
use App\Support\RepositoryCommit;

/**
 * The batch guards every corpus re-run dispatch shares, whichever tier it is (plan §4.0).
 *
 * - the run is a member of the batch's snapshot, so the diff report has its before-state;
 * - the snapshot was taken on the code now running, so the evidence binds one code revision
 *   ({@see CodeRevision}; a commit that changes only documentation keeps it);
 * - the run was not already re-run on this code, by either tier, so a canary run is not run
 *   again by its batch and an interrupted batch resumes without repeating work. A transcription
 *   round (Tier A) is the exception: it detected nothing, so its run goes on to a detection
 *   round, and Tier A itself refuses a second transcription on the commit;
 * - the run is completed and not excluded (the eligible membership). One failed run is let back
 *   in: one a round parked because its detection left a content hold with nowhere to land
 *   (canary 4, 1304 §3872), once the operator has released every hold it named. The park asks
 *   for exactly that before re-running, and the round's reset clears it;
 * - the run has a readable audio timeline, which detection refuses to run without;
 * - the run has not changed since the snapshot, so the diff reads the re-run's change alone.
 *   A finished transcription round dispatched against this snapshot may have changed the text
 *   to exactly what it recorded, so the round diff reads both rounds' change, as it did when
 *   Tier A also detected.
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
        $snapshotRefusal = $this->snapshotRefusal($run, $snapshot);

        if ($snapshotRefusal !== null) {
            return $snapshotRefusal;
        }

        foreach ($run->corpusRerunStamps() as $stamp) {
            if (! $snapshot->stampedOnItsCode($stamp)) {
                continue;
            }

            if (! RetranscribeForCorpusRerun::transcribedOnly($stamp)) {
                return sprintf('already re-run on this commit at %s', (string) ($stamp['dispatched_at'] ?? 'an unrecorded time'));
            }

            if (($stamp['transcribed_at'] ?? null) === null) {
                return 'run\'s re-transcription on this commit has not finished';
            }

            // As re-extraction refuses a round finished on older code: the text is that code's.
            if (! $snapshot->workersRanItsCode($stamp)) {
                return sprintf(
                    'run was re-transcribed by workers on %s, not %s; restart the workers and move the freeze to re-transcribe it',
                    is_string($stamp['worker_commit'] ?? null) ? $stamp['worker_commit'] : 'an unrecorded commit',
                    $snapshot->codeDescription(),
                );
            }
        }

        if ($run->status !== ProcessingStatus::Completed && ! $this->heldForEnsembleReviewByARound($run)) {
            $stillHeld = $this->unplacedHoldsStillLive($run);

            if ($stillHeld === null) {
                return sprintf('run is %s, not completed', $run->status->value);
            }

            if ($stillHeld !== []) {
                return sprintf(
                    "run is parked: section %s's content hold had nowhere to land; confirm the section to release it",
                    implode(', ', $stillHeld),
                );
            }
        }

        if ($run->isExcluded()) {
            return 'run is excluded';
        }

        if (in_array($snapshot->runs[$run->id]['audio_timeline_sha256'] ?? 'none', ['none', 'unreadable'], true)) {
            return 'run has no readable audio timeline; classify it (historic-import:classify-audio) and take a new snapshot';
        }

        $after = $this->state->capture($run);

        if ($this->diff->compare($this->expectedBefore($run, $snapshot, $after), $after)['changes'] !== []) {
            return 'run has changed since the snapshot; take a new snapshot so the diff reads this re-run alone';
        }

        return null;
    }

    /**
     * Whether the run belongs to this snapshot and the snapshot was taken on the code now running.
     */
    public function snapshotRefusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
    {
        if (! $snapshot->holds($run->id)) {
            return 'run is not a member of this snapshot';
        }

        if (! $snapshot->isOnRunningCode()) {
            return sprintf('snapshot was taken on %s but other code is running (commit %s); take a new snapshot on the frozen code', $snapshot->codeDescription(), RepositoryCommit::current() ?? 'unknown');
        }

        return null;
    }

    /**
     * The detection round a recompose continues, if the run has one: its latest stamp, dispatched
     * against this snapshot on the running commit, whose media has not been cut, and the round
     * settled with its sections written (completed, or held for the ensemble's questions).
     *
     * Answers given after such a round reach its sections only by composing its draws again
     * ({@see RecomposeForCorpusRerun}); a second detection on the commit is refused.
     *
     * @return array<string, mixed>|null
     */
    public function roundToContinue(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?array
    {
        $stamps = $run->corpusRerunStamps();
        $latest = $stamps === [] ? null : $stamps[count($stamps) - 1];

        if ($latest === null
            || RetranscribeForCorpusRerun::transcribedOnly($latest)
            || ! $snapshot->stampedOnItsCode($latest)
            || ($latest['snapshot_file_sha256'] ?? null) !== $snapshot->fileSha256
            || ($latest['media'] ?? null) !== RedetectForCorpusRerun::MEDIA_DEFERRED) {
            return null;
        }

        $settled = ($run->status === ProcessingStatus::Completed && ($latest['media_recorded_at'] ?? null) !== null)
            || $this->heldForEnsembleReviewByARound($run);

        return $settled ? $latest : null;
    }

    /**
     * The finished transcription round dispatched against this snapshot, if the run has one.
     *
     * @return array<string, mixed>|null
     */
    public function transcriptionRoundOn(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?array
    {
        $stamps = $run->corpusRerunStamps();
        $latest = $stamps === [] ? null : $stamps[count($stamps) - 1];

        if ($latest === null
            || ! RetranscribeForCorpusRerun::transcribedOnly($latest)
            || ($latest['transcribed_at'] ?? null) === null
            || ($latest['snapshot_file_sha256'] ?? null) !== $snapshot->fileSha256) {
            return null;
        }

        return $latest;
    }

    /**
     * The snapshot's capture of the run, carried forward over a finished transcription round
     * dispatched against it: the text becomes the text the round recorded, and the step the run
     * ended on is its own. The round dispatched only because the run matched the snapshot then.
     *
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function expectedBefore(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot, array $after): array
    {
        $before = $snapshot->runs[$run->id];
        $round = $this->transcriptionRoundOn($run, $snapshot);

        if ($round === null || ! is_string($round['transcript_sha256'] ?? null)) {
            return $before;
        }

        return [...$before, 'transcript_sha256' => $round['transcript_sha256'], 'current_step' => $after['current_step'] ?? null];
    }

    /**
     * A run a corpus re-run round held for the ensemble's questions. Re-detecting it is the
     * round's own work (canary 10 re-ran canary 9's held runs); its earlier bundles stay banked,
     * so the questions survive. A hold from routine processing is not the round's to override.
     */
    private function heldForEnsembleReviewByARound(MediaProcessingLog $run): bool
    {
        return $run->status === ProcessingStatus::Failed
            && $run->corpusRerunStamps() !== []
            && data_get($run->processing_metadata?->toArray() ?? [], 'manual_review.reason_code') === 'service_structure_ensemble_review';
    }

    /**
     * For a run a corpus re-run round parked on an unplaced content hold, the sections it named
     * that are still held; null for any other run that is not completed.
     *
     * @return list<int>|null
     */
    private function unplacedHoldsStillLive(MediaProcessingLog $run): ?array
    {
        $metadata = $run->processing_metadata?->toArray() ?? [];

        if ($run->status !== ProcessingStatus::Failed
            || $run->corpusRerunStamps() === []
            || data_get($metadata, 'manual_review.reason_code') !== 'unplaced_content_hold'
            || data_get($metadata, 'service_structure_proposal.refused_reason') !== 'unplaced_content_hold') {
            return null;
        }

        $sectionIds = array_values(array_filter((array) data_get($metadata, 'service_structure_proposal.unplaced_content_hold_section_ids', []), 'is_int'));

        return array_values(ServiceSection::query()
            ->whereKey($sectionIds)
            ->orderBy('id')
            ->get()
            ->filter(static function (ServiceSection $section): bool {
                $flags = $section->metadata?->toArray()['review_flags'] ?? [];

                return HoldSectionForContentReview::isHeld(is_array($flags) ? array_values(array_filter($flags, 'is_string')) : []);
            })
            ->map(static fn (ServiceSection $section): int => (int) $section->id)
            ->all());
    }
}
