<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricRerunState;
use App\Services\HistoricMedia\QueuedRunJobs;
use App\Services\Processing\HistoricWorkingCopyReachability;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Close a corpus re-run round that will never finish, so the run reads as it did before the
 * round was dispatched (plan FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13 §4).
 *
 * The full pass left 99 runs `processing` with nothing queued: 84 cuts pulled for the amended-D1
 * hold list after their edges were decoded, and 15 re-transcriptions that never finished. Six
 * more failed for faults since fixed. A new round admits only completed runs, and a fresh
 * snapshot cannot make these eligible, so each round is withdrawn first.
 *
 * Only what can be shown to have changed nothing is withdrawn:
 *
 * - an extraction whose cut was never stored: no sermon video recorded since it was dispatched.
 *   The stamp goes back to deferred media, as {@see ExtractForCorpusRerun} does when the
 *   orchestrator refuses a dispatch, and the re-extraction request it raised is cleared;
 * - a re-transcription that never finished, whose transcript still hashes as the snapshot
 *   recorded it. Its stamp is marked withdrawn, which every reader of the stamps then passes
 *   over ({@see MediaProcessingLog::corpusRerunStamps()}).
 *
 * Refused while any job still names the run (waiting, delayed or reserved), while its media work
 * is unsettled, while it waits on a manual review (those have their own routes back), for a run
 * the snapshot did not record as completed, and for an extraction that changed the run's sections
 * or bound media to them. Step rows the round left active, with no job behind them, are
 * cancelled; the run then returns to `completed`. Nothing else is written; the withdrawal stays on
 * the stamp as history.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class WithdrawCorpusRerunRound
{
    public function __construct(
        private readonly QueuedRunJobs $queuedJobs,
        private readonly HistoricWorkingCopyReachability $workingCopies,
        private readonly HistoricRerunState $state,
        private readonly MediaProcessingRunTransitionService $transitions,
        private readonly SermonExtractionPlanResolver $compositions,
    ) {}

    /**
     * @return array{outcome: 'ready'|'withdrawn'|'refused', reason: string}
     */
    public function execute(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot, string $reason, bool $execute): array
    {
        $refusal = $this->refusal($run, $snapshot);

        if (is_string($refusal)) {
            return ['outcome' => 'refused', 'reason' => $refusal];
        }

        $kind = $refusal['kind'];

        if (! $execute) {
            return ['outcome' => 'ready', 'reason' => "ready to withdraw its unfinished {$kind}"];
        }

        $withdrawal = ['at' => now()->toIso8601String(), 'reason' => $reason, 'status' => $run->status->value, 'current_step' => $run->current_step];

        if ($kind === 'extraction') {
            $run->amendLatestCorpusRerunStamp([
                'media' => RedetectForCorpusRerun::MEDIA_DEFERRED,
                'extraction_dispatched_at' => null,
                'extraction_withdrawn' => [...$withdrawal, 'dispatched_at' => $refusal['stamp']['extraction_dispatched_at'] ?? null],
            ]);
            $run->writeProcessingMetadata(static function (array $metadata): array {
                unset($metadata['re_extraction']);

                return $metadata;
            });
        } else {
            $run->amendLatestCorpusRerunStamp(['withdrawn' => $withdrawal]);
        }

        $cancelled = $this->cancelRoundSteps($run, $refusal['stamp'], $reason);
        $this->transitions->markAsCompleted($run->fresh() ?? $run, (string) ($snapshot->runs[$run->id]['current_step'] ?? 'completed'));

        return ['outcome' => 'withdrawn', 'reason' => "withdrew its unfinished {$kind}".($cancelled > 0 ? " and cancelled {$cancelled} step(s) it left active" : '')];
    }

    /**
     * What the extraction did to the run's sections, which withdrawal cannot undo (plan §4: sections
     * unchanged since the detection round, no new media bound): a section made or cut since it was
     * dispatched, a song video bound since, or composition inputs other than those the detection
     * round composed from.
     *
     * @param  array<string, mixed>  $stamp
     */
    private function sectionEffectSince(MediaProcessingLog $run, Carbon $dispatchedAt, array $stamp): ?string
    {
        $sections = $run->serviceSections();

        if ((clone $sections)->where('created_at', '>=', $dispatchedAt)->exists()) {
            return 'a section was made after the extraction was dispatched';
        }

        if ((clone $sections)->where('extracted_at', '>=', $dispatchedAt)->exists()) {
            return 'a section was cut after the extraction was dispatched';
        }

        if (SongVideo::query()->whereIn('service_section_id', (clone $sections)->select('id'))
            ->where(static fn (Builder $query): Builder => $query->where('created_at', '>=', $dispatchedAt)->orWhere('updated_at', '>=', $dispatchedAt))->exists()) {
            return 'a song video was bound after the extraction was dispatched';
        }

        $recorded = data_get($stamp, 'deferred_extraction_plan.sermon_boundary.input_identity')
            ?? data_get($run->processing_metadata?->toArray(), 'sermon_composition.input_identity');

        if (! is_string($recorded)) {
            return 'no composition was recorded to show its sections are unchanged';
        }

        if ($this->compositions->currentInputIdentity($run) !== $recorded) {
            return 'its sections have changed since they were composed for the extraction';
        }

        return null;
    }

    /**
     * Step rows the round started and left active. With no job naming the run they are not
     * running, and they would otherwise read as live work on a completed run. Rows active from
     * before the round are left: the snapshot recorded the run completed with them.
     *
     * @param  array<string, mixed>  $stamp
     */
    private function cancelRoundSteps(MediaProcessingLog $run, array $stamp, string $reason): int
    {
        $dispatchedAt = $stamp['dispatched_at'] ?? null;

        if (! is_string($dispatchedAt)) {
            return 0;
        }

        return SermonProcessingStep::query()
            ->where('processing_id', $run->processing_id)
            ->whereIn('status', [ProcessingStatus::Pending->value, ProcessingStatus::Started->value, ProcessingStatus::Processing->value])
            ->where('started_at', '>=', Carbon::parse($dispatchedAt))
            ->update(['status' => ProcessingStatus::Cancelled->value, 'message' => 'Withdrawn with its round: '.$reason, 'completed_at' => now()]);
    }

    /**
     * @return string|array{kind: 'extraction'|'re-transcription', stamp: array<string, mixed>}
     */
    private function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): string|array
    {
        if (! $snapshot->holds($run->id)) {
            return 'run is not a member of this snapshot';
        }

        $stamps = $run->corpusRerunStamps();
        $stamp = $stamps === [] ? null : $stamps[count($stamps) - 1];

        if ($stamp === null || ($stamp['snapshot_file_sha256'] ?? null) !== $snapshot->fileSha256) {
            return 'run\'s latest round was not dispatched against this snapshot';
        }

        if (! in_array($run->status, [ProcessingStatus::Processing, ProcessingStatus::Pending, ProcessingStatus::Failed], true)) {
            return sprintf('run is %s; only an unfinished or failed round is withdrawn', $run->status->value);
        }

        // A flag left from an earlier review (1209's dates from 09-17, before the run completed)
        // does not hold an unfinished round; a run the round failed into review does.
        if ($run->status === ProcessingStatus::Failed && data_get($run->processing_metadata?->toArray(), 'manual_review.status') === 'required') {
            return 'run waits on a manual review, which has its own route back into a round';
        }

        if (($snapshot->runs[$run->id]['status'] ?? null) !== ProcessingStatus::Completed->value) {
            return 'the snapshot did not record the run as completed, so there is no completed state to return it to';
        }

        $queued = $this->queuedJobs->naming($run->id);

        if ($queued !== []) {
            return 'jobs still name the run: '.implode('; ', $queued);
        }

        $unsettled = $this->workingCopies->unsettledWork($run);

        if ($unsettled !== null) {
            return 'its media work is unsettled: '.$unsettled['description'];
        }

        if (RetranscribeForCorpusRerun::transcribedOnly($stamp)) {
            if (($stamp['transcribed_at'] ?? null) !== null) {
                return 'its re-transcription finished; a finished round is not withdrawn';
            }

            if ($this->state->capture($run)['transcript_sha256'] !== ($snapshot->runs[$run->id]['transcript_sha256'] ?? null)) {
                return 'its transcript has changed since the snapshot, so the re-transcription did write';
            }

            return ['kind' => 're-transcription', 'stamp' => $stamp];
        }

        if (($stamp['media'] ?? null) !== ExtractForCorpusRerun::MEDIA_EXTRACTED || ! is_string($stamp['extraction_dispatched_at'] ?? null)) {
            return 'its latest round is not an unfinished extraction or re-transcription';
        }

        $dispatchedAt = Carbon::parse($stamp['extraction_dispatched_at']);
        $storedAt = data_get($run->processing_metadata?->toArray(), 'stored_video.stored_at');

        if (is_string($storedAt) && Carbon::parse($storedAt)->gte($dispatchedAt)) {
            return sprintf('a sermon video was stored at %s, after the extraction was dispatched', $storedAt);
        }

        $sectionEffect = $this->sectionEffectSince($run, $dispatchedAt, $stamp);

        if ($sectionEffect !== null) {
            return $sectionEffect;
        }

        return ['kind' => 'extraction', 'stamp' => $stamp];
    }
}
