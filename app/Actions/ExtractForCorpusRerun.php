<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\StagedSourceVerification;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Support\RepositoryCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cut the media a corpus re-run detection round deferred, once, on the frozen commit
 * (plan §4.0, Tier C).
 *
 * A detection round ({@see RedetectForCorpusRerun}) stops before extraction and stamps its
 * media as deferred. This re-extracts from the round's structure through the orchestrator's
 * existing entry point ({@see ProcessingRunOrchestrator::reExtract()}): sermon cut, analysis,
 * video quality, section candidates and their review, promotion and cleanup.
 *
 * Only a round on the running commit qualifies, so media is never cut from a structure an
 * earlier commit detected, and only one whose worker booted on that commit, because the
 * dispatching command's commit says nothing about the code a stale worker ran; a round that has not finished recording is refused, as is a run
 * already extracted. The staged source must still be the recorded one, because the cut
 * reads the recording against timings that describe the original.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class ExtractForCorpusRerun
{
    public const MEDIA_EXTRACTED = 'extracted';

    public function __construct(
        private readonly ProcessingRunOrchestrator $orchestrator,
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly StagedSourceVerification $stagedSource,
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
            return ['outcome' => 'ready', 'reason' => 'ready for extraction'];
        }

        $run->amendLatestCorpusRerunStamp([
            'media' => self::MEDIA_EXTRACTED,
            'extraction_dispatched_at' => now()->toIso8601String(),
        ]);

        $result = $this->orchestrator->reExtract($run->fresh() ?? $run);

        if (! $result->success) {
            $run->amendLatestCorpusRerunStamp([
                'media' => RedetectForCorpusRerun::MEDIA_DEFERRED,
                'extraction_dispatched_at' => null,
            ]);

            return ['outcome' => 'refused', 'reason' => 'orchestrator refused: '.$result->message];
        }

        Log::info('Dispatched corpus re-run extraction', [
            'processing_id' => $run->processing_id,
            'git_commit' => $snapshot->gitCommit,
            'membership_sha256' => $snapshot->membershipSha256,
        ]);

        return ['outcome' => 'dispatched', 'reason' => 'dispatched from extraction'];
    }

    /**
     * Cheap checks first; the staged source is checked last, as it is the only one on the drive.
     */
    private function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
    {
        if (! $snapshot->holds($run->id)) {
            return 'run is not a member of this snapshot';
        }

        $commit = RepositoryCommit::current();

        if ($snapshot->gitCommit === null || $snapshot->gitCommit !== $commit) {
            return sprintf('snapshot was taken on %s but %s is running; extract on the commit the round detected on', $snapshot->gitCommit ?? 'an unknown commit', $commit ?? 'an unknown commit');
        }

        $stamps = $run->corpusRerunStamps();
        $latest = $stamps === [] ? null : $stamps[count($stamps) - 1];

        if ($latest === null || ($latest['git_commit'] ?? null) !== $commit) {
            return 'run has no detection round on this commit; re-detect it first';
        }

        // Its sections were detected on the text it replaced.
        if (RetranscribeForCorpusRerun::transcribedOnly($latest)) {
            return 'run was re-transcribed on this commit but not re-detected; re-detect it first (historic-import:rerun-redetect)';
        }

        if (($latest['media'] ?? null) === self::MEDIA_EXTRACTED) {
            return sprintf('media already extracted on this commit at %s', (string) ($latest['extraction_dispatched_at'] ?? 'an unrecorded time'));
        }

        if (($latest['media'] ?? null) !== RedetectForCorpusRerun::MEDIA_DEFERRED) {
            return 'run\'s latest round on this commit cut its own media';
        }

        if (($latest['media_recorded_at'] ?? null) === null || $run->status !== ProcessingStatus::Completed) {
            return sprintf('detection round has not finished (run is %s)', $run->status->value);
        }

        $workerCommit = $latest['worker_commit'] ?? null;

        if ($workerCommit !== $commit) {
            return sprintf(
                'detection round finished on worker code from %s, not %s; restart the workers and re-detect',
                is_string($workerCommit) ? $workerCommit : 'an unrecorded commit',
                $commit,
            );
        }

        if ($run->isExcluded()) {
            return 'run is excluded';
        }

        if ($run->superseded_at !== null || $run->isRetired()) {
            return 'run is superseded or retired';
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
