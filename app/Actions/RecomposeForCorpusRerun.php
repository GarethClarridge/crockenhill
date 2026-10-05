<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\StructureRedetectionGrounds;
use App\Jobs\DetectServiceStructure;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\EnsembleReviewGate;
use App\Services\HistoricMedia\CorpusRerunGuard;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\TranscriptLossHolds;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A corpus re-run detection round that makes no draw (plan §4.0): the run's latest banked
 * four-draw ensemble is composed again under the running commit's rules and every answer the
 * operator has given on the run, then the round's chain runs on as after a fresh detection.
 *
 * Two uses. After the operator answers a round's questions, it carries the answers into the
 * sections from the very draws they were given on, on the same commit: a fresh detection would
 * cut draws nobody reviewed and, measured on canary 9's answers over the §6 draws, ask again in
 * one re-run in five. After a rule is adopted (a new commit), it brings an uncut run onto the new
 * commit's rules without paying for draws or raising their new disagreements.
 *
 * Refused where the draws no longer fit: no banked ensemble, an input that no longer matches
 * the run (re-transcribed, new order of service), or a run that belongs to Tier A. Those are
 * re-detected ({@see RedetectForCorpusRerun}).
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class RecomposeForCorpusRerun
{
    /** The stamp's `detection` for a round composed from banked draws rather than fresh ones. */
    public const DETECTION_RECOMPOSE = 'recompose';

    public function __construct(
        private readonly ProcessingRunOrchestrator $orchestrator,
        private readonly CorpusRerunGuard $guard,
        private readonly TranscriptLossHolds $transcriptLoss,
        private readonly EnsembleReviewGate $ensembleGate,
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

        $attemptId = $this->latestAttemptId($run);

        if ($attemptId === null) {
            return ['outcome' => 'refused', 'reason' => 'run has no banked ensemble draws; re-detect it (historic-import:rerun-redetect)'];
        }

        if (! $execute) {
            return ['outcome' => 'ready', 'reason' => 'ready to recompose banked draws '.$attemptId];
        }

        $run->putCorpusRerunStamp([
            'grounds' => StructureRedetectionGrounds::CorpusRerun->value,
            'git_commit' => $snapshot->gitCommit,
            'code_revision' => $snapshot->codeRevision,
            'snapshot_file_sha256' => $snapshot->fileSha256,
            'membership_sha256' => $snapshot->membershipSha256,
            'media' => RedetectForCorpusRerun::MEDIA_DEFERRED,
            'detection' => self::DETECTION_RECOMPOSE,
            'recomposed_attempt_id' => $attemptId,
            'dispatched_at' => now()->toIso8601String(),
        ]);
        $this->putRequest($run, ['attempt_id' => $attemptId, 'requested_at' => now()->toIso8601String()]);

        $result = $this->orchestrator->redetectServiceStructure($run->fresh() ?? $run, StructureRedetectionGrounds::CorpusRerun);

        if (! $result->success) {
            $this->putRequest($run, null);
            $run->putCorpusRerunStamp(null);

            return ['outcome' => 'refused', 'reason' => 'orchestrator refused: '.$result->message];
        }

        Log::info('Dispatched corpus re-run recomposition', [
            'processing_id' => $run->processing_id,
            'attempt_id' => $attemptId,
            'git_commit' => $snapshot->gitCommit,
            'membership_sha256' => $snapshot->membershipSha256,
        ]);

        return ['outcome' => 'dispatched', 'reason' => 'dispatched: banked draws recomposed, media deferred'];
    }

    /**
     * Why a round has not really been recomposed, or null when it has (or asked for no
     * recomposition). A request still waiting means the detection job never settled it; a
     * composition older than the round, or of another attempt, is the previous round's. Either
     * way the run's sections are not the recomposition this round dispatched, though the round's
     * later jobs may have run on and the projection still match the composition it has (run 1112,
     * canary 11). Read before a round is recorded and before its media is cut.
     *
     * @param  array<string, mixed>  $stamp  the round's corpus re-run stamp
     */
    public static function unfinishedRecomposition(MediaProcessingLog $run, array $stamp): ?string
    {
        $metadata = $run->processing_metadata?->raw ?? [];
        $request = $metadata[DetectServiceStructure::RECOMPOSE_KEY] ?? null;

        if ($request !== null) {
            $requestedAt = is_array($request) && is_string($request['requested_at'] ?? null) ? $request['requested_at'] : 'an unrecorded time';

            return sprintf('recompose request of %s is still outstanding: the detection job never recomposed this round', $requestedAt);
        }

        if (($stamp['detection'] ?? null) !== self::DETECTION_RECOMPOSE) {
            return null;
        }

        $bank = $metadata['service_structure_ensemble'] ?? null;
        $latest = is_array($bank) && $bank !== [] ? end($bank) : null;
        $attemptId = is_array($latest) ? ($latest['attempt_id'] ?? null) : null;

        if ($attemptId === null || $attemptId !== ($stamp['recomposed_attempt_id'] ?? null)) {
            return 'the latest banked attempt is not the attempt this round recomposed';
        }

        $recomposedAt = $latest['composition']['recomposed_at'] ?? null;
        $dispatchedAt = $stamp['dispatched_at'] ?? null;

        if (! is_string($recomposedAt) || ! is_string($dispatchedAt)
            || Carbon::parse($recomposedAt)->lessThan(Carbon::parse($dispatchedAt))) {
            return sprintf(
                'the latest composition was recorded at %s, before this round was dispatched at %s',
                is_string($recomposedAt) ? $recomposedAt : 'an unrecorded time',
                is_string($dispatchedAt) ? $dispatchedAt : 'an unrecorded time',
            );
        }

        return null;
    }

    private function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
    {
        if ($this->guard->roundToContinue($run, $snapshot) !== null) {
            $snapshotRefusal = $this->guard->snapshotRefusal($run, $snapshot);

            if ($snapshotRefusal !== null) {
                return $snapshotRefusal;
            }

            if ($run->isExcluded()) {
                return 'run is excluded';
            }
        } else {
            $batchRefusal = $this->guard->refusal($run, $snapshot);

            if ($batchRefusal !== null) {
                return $batchRefusal;
            }

            if ($this->guard->transcriptionRoundOn($run, $snapshot) !== null) {
                return 'run was re-transcribed against this snapshot, so its banked draws read the old text; re-detect it (historic-import:rerun-redetect)';
            }

            if ($this->transcriptLoss->on($run) !== []) {
                return 'run is held for transcript loss; it belongs to Tier A (historic-import:rerun-retranscribe)';
            }

            if ($snapshot->listeningRoutesToRetranscription($run)) {
                return 'listening rated the new decode better; it belongs to Tier A (historic-import:rerun-retranscribe)';
            }
        }

        $evidence = $this->latestAttempt($run);

        if ($evidence === null) {
            return 'run has no banked ensemble draws; re-detect it (historic-import:rerun-redetect)';
        }

        if (! $this->ensembleGate->inputIsCurrent($run, $evidence)) {
            return 'run\'s banked ensemble input no longer matches the run; re-detect it (historic-import:rerun-redetect)';
        }

        return $this->orchestrator->structureRedetectionRefusal($run, StructureRedetectionGrounds::CorpusRerun)['message'] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function latestAttempt(MediaProcessingLog $run): ?array
    {
        $bank = $run->processing_metadata?->raw['service_structure_ensemble'] ?? null;
        $latest = is_array($bank) && $bank !== [] ? end($bank) : null;

        return is_array($latest) && is_string($latest['attempt_id'] ?? null) ? $latest : null;
    }

    private function latestAttemptId(MediaProcessingLog $run): ?string
    {
        $attemptId = $this->latestAttempt($run)['attempt_id'] ?? null;

        return is_string($attemptId) ? $attemptId : null;
    }

    /** @param  array{attempt_id: string, requested_at: string}|null  $request */
    private function putRequest(MediaProcessingLog $run, ?array $request): void
    {
        $run->writeProcessingMetadata(static function (array $metadata) use ($request): array {
            if ($request === null) {
                unset($metadata[DetectServiceStructure::RECOMPOSE_KEY]);
            } else {
                $metadata[DetectServiceStructure::RECOMPOSE_KEY] = $request;
            }

            return $metadata;
        });
    }
}
