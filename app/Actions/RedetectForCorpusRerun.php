<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\StructureRedetectionGrounds;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\CorpusRerunGuard;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\StagedSourceVerification;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-detect one member of a snapshotted corpus re-run batch (plan §4.0, Tier B).
 *
 * The corpus re-run re-detects every eligible historic run against one frozen commit, so that
 * fixes acting at structure detection and song matching reach existing runs through the
 * pipeline rather than by hand. This action adds the batch's guards ({@see CorpusRerunGuard})
 * to the ones every re-detection shares
 * ({@see ProcessingRunOrchestrator::structureRedetectionRefusal()}), and checks that the staged
 * source hashes to the recorded source, because extraction re-cuts media from it against
 * timings that describe the original.
 *
 * The dispatch is stamped on the run before it is sent and the stamp withdrawn if the
 * orchestrator refuses. Transcription is not repeated: Tier A's re-transcription is
 * {@see RetranscribeForCorpusRerun}.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class RedetectForCorpusRerun
{
    public const STAMP_KEY = 'corpus_rerun';

    public function __construct(
        private readonly ProcessingRunOrchestrator $orchestrator,
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly StagedSourceVerification $stagedSource,
        private readonly CorpusRerunGuard $guard,
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
        $batchRefusal = $this->guard->refusal($run, $snapshot);

        if ($batchRefusal !== null) {
            return $batchRefusal;
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
