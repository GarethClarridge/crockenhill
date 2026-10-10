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
use App\Services\Media\Audio\AudioChunkingService;
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
 * superseded, source present) and a hash-verified staged source, plus its own grounds, any
 * of three: a live transcript-loss hold on the text the run holds now ({@see TranscriptLossHolds}),
 * an H10b listening route of `new_better` on that same text, frozen in the snapshot
 * ({@see ListeningRouting}), or a transcript made from audio shorter than the recording
 * ({@see self::truncatedTranscript()}).
 *
 * The dispatch is the one {@see RetranscribeHistoricVideoRun} proved on 980, 1258, 1343 and
 * 1287: supersede any recovery replay stamp, reopen at `transcribe_full_service` and start the
 * chain afresh. `retry()` would reuse the stored transcript. It is a transcription round
 * (operator, 2026-10-01; it was a detection round from 2026-09-24): the chain stops before
 * detection and records the text it wrote, so Tier A can run early, off the critical path, and
 * a rule commit landing later costs its runs only a Tier B round. That round re-detects the
 * run, on the same snapshot or a later one ({@see CorpusRerunGuard}), and Tier C cuts the media.
 * The corpus re-run stamp is written first and withdrawn if the dispatch fails; a run is
 * re-transcribed at most once on a commit.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class RetranscribeForCorpusRerun
{
    public const TIER = 'retranscription';

    /** A transcription round's stamp: the round wrote new text and detected nothing. */
    public const DETECTION_NONE = 'none';

    /** How much shorter than the recording the audio a transcript was made from may be. */
    private const TRUNCATION_SECONDS = 60.0;

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
        $truncated = $this->truncatedTranscript($run);
        $grounds = match (true) {
            $holds !== [] => sprintf('%d transcript-loss hold%s', count($holds), count($holds) === 1 ? '' : 's'),
            $listening => 'listening rated the new decode better',
            $truncated !== null => sprintf('transcript made from %.0f of %.0f s of the recording', $truncated['transcribed'], $truncated['recording']),
            default => throw new \LogicException('A run that passed the grounds check has no grounds.'),
        };

        if (! $execute) {
            return ['outcome' => 'ready', 'reason' => "ready for re-transcription ({$grounds})"];
        }

        $run->putCorpusRerunStamp([
            'grounds' => StructureRedetectionGrounds::CorpusRerun->value,
            'tier' => self::TIER,
            'transcript_loss_sections' => array_values(array_unique(array_column($holds, 'section'))),
            'listening_routing_sha256' => $listening ? $snapshot->listening?->fileSha256 : null,
            'detection' => self::DETECTION_NONE,
            'git_commit' => $snapshot->gitCommit,
            'code_revision' => $snapshot->codeRevision,
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

        return ['outcome' => 'dispatched', 'reason' => "dispatched from full-service transcription ({$grounds}); detection deferred to a Tier B round"];
    }

    /**
     * The audio a stored transcript was made from and the recording's length, when the first fell
     * more than a minute short of the second.
     *
     * Canary 15, run 967: the source was compressed while the staging drive detached, and the
     * transcript was made from 1,991 s of a 4,712 s recording. No hold could name that, so no
     * route could take the run back to transcription. Compression now refuses short audio
     * ({@see AudioChunkingService}); this reaches transcripts made before it.
     *
     * @return array{transcribed: float, recording: float}|null
     */
    private function truncatedTranscript(MediaProcessingLog $run): ?array
    {
        $recording = data_get($run->processing_metadata?->toArray() ?? [], 'format_details.duration');
        $contents = $run->storedServiceTranscriptContents();
        $transcribed = is_string($contents) ? data_get(json_decode($contents, true), 'duration') : null;

        if (! is_numeric($recording) || ! is_numeric($transcribed)
            || (float) $recording - (float) $transcribed <= self::TRUNCATION_SECONDS) {
            return null;
        }

        return ['transcribed' => (float) $transcribed, 'recording' => (float) $recording];
    }

    /**
     * Whether a corpus re-run stamp records a transcription round, which detected nothing.
     *
     * @param  array<string, mixed>  $stamp
     */
    public static function transcribedOnly(array $stamp): bool
    {
        return ($stamp['detection'] ?? null) === self::DETECTION_NONE;
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

            $this->orchestrator->startTranscriptionRound($lockedRun->fresh() ?? $lockedRun);
        }));
    }

    /**
     * Cheap checks first; the staged source is checked last, as it is the only one on the drive.
     */
    private function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
    {
        // The batch guard lets a transcription round through for the detection round that
        // follows it, so a second transcription on the commit is refused here.
        foreach ($run->corpusRerunStamps() as $stamp) {
            if (self::transcribedOnly($stamp) && $snapshot->stampedOnItsCode($stamp)) {
                return sprintf('already re-transcribed on this commit at %s', (string) ($stamp['dispatched_at'] ?? 'an unrecorded time'));
            }
        }

        $batchRefusal = $this->guard->refusal($run, $snapshot);

        if ($batchRefusal !== null) {
            return $batchRefusal;
        }

        if ($run->processing_type !== MediaType::Livestream) {
            return 'run is not a livestream pipeline';
        }

        if ($this->transcriptLoss->on($run) === [] && ! $snapshot->listeningRoutesToRetranscription($run) && $this->truncatedTranscript($run) === null) {
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
