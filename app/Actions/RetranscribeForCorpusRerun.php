<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MediaType;
use App\Enums\StructureRedetectionGrounds;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\CorpusRerunGuard;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\ListeningRouting;
use App\Services\HistoricMedia\StagedSourceVerification;
use App\Services\HistoricMedia\TranscriptLossHolds;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Re-transcribe one member of a snapshotted corpus re-run batch (plan §4.0, Tier A).
 *
 * Tier B re-detects from the stored transcript ({@see RedetectForCorpusRerun}). A run whose
 * stored text is known to be wrong where its audio is not gains nothing from that, so Tier A
 * starts it again from full-service transcription and lets detection follow. It carries the
 * batch's guards ({@see CorpusRerunGuard}), the shared re-detection guards (not retired or
 * superseded, source present) and a hash-verified staged source, plus its own grounds, either
 * of two: a live transcript-loss hold on the text the run holds now ({@see TranscriptLossHolds}),
 * or an H10b listening route of `new_better` on that same text, frozen in the snapshot
 * ({@see ListeningRouting}).
 *
 * The dispatch is the one {@see RetranscribeHistoricVideoRun} proved on 980, 1258, 1343 and
 * 1287: supersede any recovery replay stamp, reopen at `transcribe_full_service` and start the
 * chain afresh. `retry()` would reuse the stored transcript. Like Tier B, it is a detection
 * round (operator, 2026-09-24): the chain stops before extraction and records what would be
 * cut, and Tier C cuts the media once on the frozen commit. The corpus re-run stamp is written
 * first and withdrawn if the dispatch fails, and it also keeps Tier B from re-detecting the run
 * again on the same commit.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class RetranscribeForCorpusRerun
{
    public const TIER = 'retranscription';

    public function __construct(
        private readonly CorpusRerunGuard $guard,
        private readonly TranscriptLossHolds $transcriptLoss,
        private readonly ProcessingRunOrchestrator $orchestrator,
        private readonly MediaProcessingRunTransitionService $transitions,
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

        $holds = $this->transcriptLoss->on($run);
        $listening = $snapshot->listeningRoutesToRetranscription($run);
        $grounds = $holds === []
            ? 'listening rated the new decode better'
            : sprintf('%d transcript-loss hold%s', count($holds), count($holds) === 1 ? '' : 's');

        if (! $execute) {
            return ['outcome' => 'ready', 'reason' => "ready for re-transcription ({$grounds})"];
        }

        $run->putCorpusRerunStamp([
            'grounds' => StructureRedetectionGrounds::CorpusRerun->value,
            'tier' => self::TIER,
            'transcript_loss_sections' => array_values(array_unique(array_column($holds, 'section'))),
            'listening_routing_sha256' => $listening ? $snapshot->listening?->fileSha256 : null,
            'media' => RedetectForCorpusRerun::MEDIA_DEFERRED,
            'git_commit' => $snapshot->gitCommit,
            'snapshot_file_sha256' => $snapshot->fileSha256,
            'membership_sha256' => $snapshot->membershipSha256,
            'dispatched_at' => now()->toIso8601String(),
        ]);

        try {
            $this->dispatch($run);
        } catch (Throwable $exception) {
            $run->putCorpusRerunStamp(null);

            return ['outcome' => 'refused', 'reason' => 'dispatch failed: '.$exception->getMessage()];
        }

        Log::info('Dispatched corpus re-run re-transcription', [
            'processing_id' => $run->processing_id,
            'git_commit' => $snapshot->gitCommit,
            'membership_sha256' => $snapshot->membershipSha256,
        ]);

        return ['outcome' => 'dispatched', 'reason' => "dispatched from full-service transcription ({$grounds}); media deferred"];
    }

    /**
     * Inside the run's staging context, as {@see RetranscribeHistoricVideoRun} dispatches.
     */
    private function dispatch(MediaProcessingLog $run): void
    {
        $context = $run->historicStagingContext() ?? throw new RuntimeException('run has no historic staging context');

        $this->stagingContexts->within($context, fn () => DB::transaction(function () use ($run): void {
            $lockedRun = MediaProcessingLog::query()->lockForUpdate()->findOrFail($run->id);
            $lockedRun->supersedeTranscriptRecoveryReplay('corpus_rerun_retranscription');
            $lockedRun->refresh();

            if (! $this->transitions->markAsReopened($lockedRun, 'transcribe_full_service')) {
                throw new RuntimeException('run could not be reopened');
            }

            $this->orchestrator->startDetectionRound($lockedRun->fresh() ?? $lockedRun);
        }));
    }

    /**
     * Cheap checks first; the source hash reads the whole recording, so it runs last.
     */
    private function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
    {
        $batchRefusal = $this->guard->refusal($run, $snapshot);

        if ($batchRefusal !== null) {
            return $batchRefusal;
        }

        if ($run->processing_type !== MediaType::Livestream) {
            return 'run is not a livestream pipeline';
        }

        if ($this->transcriptLoss->on($run) === [] && ! $snapshot->listeningRoutesToRetranscription($run)) {
            return 'run has no live transcript-loss hold and no new_better listening route on its current transcript, so there are no grounds to re-transcribe it';
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
