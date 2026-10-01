<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\StructureRedetectionGrounds;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\CorpusRerunGuard;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\ListeningRouting;
use App\Services\HistoricMedia\TranscriptLossHolds;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Support\Facades\Log;

/**
 * Re-detect one member of a snapshotted corpus re-run batch (plan §4.0, Tier B).
 *
 * The corpus re-run re-detects every eligible historic run against one frozen commit, so that
 * fixes acting at structure detection and song matching reach existing runs through the
 * pipeline rather than by hand. This action adds the batch's guards ({@see CorpusRerunGuard})
 * to the ones every re-detection shares
 * ({@see ProcessingRunOrchestrator::structureRedetectionRefusal()}), and refuses a run held for
 * transcript loss or routed `new_better` by the snapshot's listening, which are Tier A's
 * ({@see TranscriptLossHolds}, {@see ListeningRouting}).
 *
 * Each dispatch is a detection round: the chain stops before extraction, because rounds are
 * repeated after every detector fix and nothing they judge needs media. The stamp records the
 * media as deferred; {@see ExtractForCorpusRerun} cuts it once, on the frozen commit, and is
 * where the staged source is checked, because the cut is what reads the recording.
 *
 * The dispatch is stamped on the run before it is sent and the stamp withdrawn if the
 * orchestrator refuses. Transcription is not repeated: Tier A's re-transcription is
 * {@see RetranscribeForCorpusRerun}, which stops before detection, so a run it re-transcribed
 * against this snapshot is this tier's whatever its grounds still say.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class RedetectForCorpusRerun
{
    public const STAMP_KEY = 'corpus_rerun';

    /** A detection round's stamp: media is cut once, later, by re-extraction on the frozen commit. */
    public const MEDIA_DEFERRED = 'deferred';

    public function __construct(
        private readonly ProcessingRunOrchestrator $orchestrator,
        private readonly CorpusRerunGuard $guard,
        private readonly TranscriptLossHolds $transcriptLoss,
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
            'media' => self::MEDIA_DEFERRED,
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

        return ['outcome' => 'dispatched', 'reason' => 'dispatched from structure detection; media deferred'];
    }

    private function refusal(MediaProcessingLog $run, HistoricRerunSnapshot $snapshot): ?string
    {
        $batchRefusal = $this->guard->refusal($run, $snapshot);

        if ($batchRefusal !== null) {
            return $batchRefusal;
        }

        // A run Tier A has re-transcribed against this snapshot is this round's, whatever its
        // grounds still say: identical text would otherwise leave it reachable by neither tier.
        if ($this->guard->transcriptionRoundOn($run, $snapshot) !== null) {
            return $this->orchestrator->structureRedetectionRefusal($run, StructureRedetectionGrounds::CorpusRerun)['message'] ?? null;
        }

        // Re-detecting known-wrong text would stamp the run on this commit and leave Tier A
        // unable to reach it (plan §4.0: Tier B leaves out runs held for transcript loss).
        if ($this->transcriptLoss->on($run) !== []) {
            return 'run is held for transcript loss; it belongs to Tier A (historic-import:rerun-retranscribe)';
        }

        if ($snapshot->listeningRoutesToRetranscription($run)) {
            return 'listening rated the new decode better; it belongs to Tier A (historic-import:rerun-retranscribe)';
        }

        return $this->orchestrator->structureRedetectionRefusal($run, StructureRedetectionGrounds::CorpusRerun)['message'] ?? null;
    }
}
