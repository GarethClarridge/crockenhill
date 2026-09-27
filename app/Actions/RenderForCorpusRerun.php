<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProcessingStatus;
use App\Jobs\RenderDeferredCuts;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\RepositoryCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Render the cuts a corpus re-run's Tier C deferred, between batches (plan §4.0, "cut now,
 * render later").
 *
 * {@see ExtractForCorpusRerun} smart-cuts every source and stamps `render: deferred`; this
 * dispatches {@see RenderDeferredCuts} for a run whose Tier C has finished, onto the ffmpeg
 * queue, and only that job stamps `rendered`. Only a run cut on the running commit qualifies,
 * so the render's encoder settings are the frozen commit's. A run parked for its held sermon has
 * not finished: its re-cut defers its render too, and renders once it completes.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class RenderForCorpusRerun
{
    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly HistoricProcessingThroughput $throughput,
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
            return ['outcome' => 'ready', 'reason' => 'ready for render'];
        }

        $context = $run->historicStagingContext();

        if ($context === null) {
            return ['outcome' => 'refused', 'reason' => 'run has no historic staging context'];
        }

        try {
            // Dispatched inside the run's context, so the worker activates it and the job
            // routes to the historic ffmpeg queue like every other media step.
            $this->stagingContexts->within($context, function () use ($run): void {
                dispatch((new RenderDeferredCuts($run))->onQueue($this->throughput->historicQueueFor(RenderDeferredCuts::class)));
            });
        } catch (Throwable $exception) {
            return ['outcome' => 'refused', 'reason' => 'could not dispatch in the run\'s staging context: '.$exception->getMessage()];
        }

        $run->amendLatestCorpusRerunStamp(['render_dispatched_at' => now()->toIso8601String()]);

        Log::info('Dispatched corpus re-run render', [
            'processing_id' => $run->processing_id,
            'git_commit' => $snapshot->gitCommit,
            'membership_sha256' => $snapshot->membershipSha256,
        ]);

        return ['outcome' => 'dispatched', 'reason' => 'dispatched'];
    }

    private function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
    {
        if (! $snapshot->holds($run->id)) {
            return 'run is not a member of this snapshot';
        }

        $commit = RepositoryCommit::current();

        if ($snapshot->gitCommit === null || $snapshot->gitCommit !== $commit) {
            return sprintf('snapshot was taken on %s but %s is running; render on the commit that cut it', $snapshot->gitCommit ?? 'an unknown commit', $commit ?? 'an unknown commit');
        }

        $stamps = $run->corpusRerunStamps();
        $latest = $stamps === [] ? null : $stamps[count($stamps) - 1];

        if (($latest['render'] ?? null) === ExtractForCorpusRerun::RENDER_RENDERED) {
            return sprintf('already rendered at %s', (string) ($latest['rendered_at'] ?? 'an unrecorded time'));
        }

        if (! $run->defersCorpusRerunRender()) {
            return 'Tier C has not cut this run with its render deferred';
        }

        if (($latest['git_commit'] ?? null) !== $commit) {
            return 'run was cut on another commit; re-run its round and Tier C on this one';
        }

        if ($run->status !== ProcessingStatus::Completed) {
            return sprintf('Tier C has not finished (run is %s)', $run->status->value);
        }

        if ($run->isExcluded()) {
            return 'run is excluded';
        }

        if ($run->superseded_at !== null || $run->isRetired()) {
            return 'run is superseded or retired';
        }

        return null;
    }
}
