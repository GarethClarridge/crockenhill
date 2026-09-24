<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProcessingStatus;
use App\Enums\StructureRedetectionGrounds;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunDiff;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricRerunState;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\StagedSourceVerification;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Support\RepositoryCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-detect one member of a snapshotted corpus re-run batch (plan §4.0, Tier B).
 *
 * The corpus re-run re-detects every eligible historic run against one frozen commit, so that
 * fixes acting at structure detection and song matching reach existing runs through the
 * pipeline rather than by hand. This action adds the batch's own guards to the ones every
 * re-detection shares ({@see ProcessingRunOrchestrator::structureRedetectionRefusal()}):
 *
 * - the run is a member of the batch's snapshot, so the diff report has its before-state;
 * - the snapshot was taken on the commit now running, so the evidence binds one commit;
 * - the run is completed and not excluded (the eligible membership);
 * - the run has not changed since the snapshot, so the diff reads the re-run's change alone;
 * - the run was not already re-run on this commit, so a canary run is not run again by its
 *   batch and an interrupted batch resumes without repeating work;
 * - the staged source hashes to the recorded source, because extraction re-cuts media from it
 *   against timings that describe the original.
 *
 * The dispatch is stamped on the run before it is sent and the stamp withdrawn if the
 * orchestrator refuses. Transcription is not repeated: Tier A's re-transcription is a separate
 * route.
 *
 * Delete with the corpus re-run's other instruments once its batches are accepted.
 */
final class RedetectForCorpusRerun
{
    public const STAMP_KEY = 'corpus_rerun';

    public function __construct(
        private readonly ProcessingRunOrchestrator $orchestrator,
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly StagedSourceVerification $stagedSource,
        private readonly HistoricRerunState $state,
        private readonly HistoricRerunDiff $diff,
    ) {}

    /**
     * @return array{outcome: 'ready'|'dispatched'|'refused', reason: string}
     */
    public function execute(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot, bool $execute): array
    {
        $refusal = $this->refusal($run, $snapshot);

        if ($refusal !== null) {
            return ['outcome' => 'refused', 'reason' => $refusal];
        }

        if (! $execute) {
            return ['outcome' => 'ready', 'reason' => 'ready for re-detection'];
        }

        $run->putCorpusRerunStamp([
            'grounds' => StructureRedetectionGrounds::CorpusRerun->value,
            'git_commit' => $snapshot->gitCommit,
            'snapshot_file_sha256' => $snapshot->fileSha256,
            'membership_sha256' => $snapshot->membershipSha256,
            'dispatched_at' => now()->toIso8601String(),
        ]);

        $result = $this->orchestrator->redetectServiceStructure($run->fresh() ?? $run, StructureRedetectionGrounds::CorpusRerun);

        if (! $result->success) {
            $run->putCorpusRerunStamp(null);

            return ['outcome' => 'refused', 'reason' => 'orchestrator refused: '.$result->message];
        }

        Log::info('Dispatched corpus re-run re-detection', [
            'processing_id' => $run->processing_id,
            'git_commit' => $snapshot->gitCommit,
            'membership_sha256' => $snapshot->membershipSha256,
        ]);

        return ['outcome' => 'dispatched', 'reason' => 'dispatched from structure detection'];
    }

    /**
     * Cheap checks first; the source hash reads the whole recording, so it runs last.
     */
    private function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
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

        $orchestratorRefusal = $this->orchestrator->structureRedetectionRefusal($run, StructureRedetectionGrounds::CorpusRerun);

        if ($orchestratorRefusal !== null) {
            return $orchestratorRefusal['message'];
        }

        $context = $run->historicStagingContext();

        if ($context === null) {
            return 'run has no historic staging context';
        }

        try {
            return $this->stagingContexts->within($context, fn (): ?string => $this->stagedSource->refusal($run));
        } catch (Throwable $exception) {
            return 'staging context unavailable: '.$exception->getMessage();
        }
    }
}
