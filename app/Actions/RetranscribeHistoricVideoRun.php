<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RetranscribeHistoricVideoRun
{
    /**
     * Each run is added by its own reviewed commit. 1287 (2026-09-23): context drift lost
     * its second song, so the detector split the first across two sections.
     *
     * @var list<int>
     */
    public const ALLOWED_RUN_IDS = [980, 1258, 1343, 1287];

    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly MediaProcessingRunTransitionService $transitions,
        private readonly ProcessingRunOrchestrator $orchestrator,
    ) {}

    /** @return array{outcome: 'ready'|'dispatched'|'refused', reason: string} */
    public function execute(MediaProcessingLog $run, bool $execute): array
    {
        if (! in_array($run->id, self::ALLOWED_RUN_IDS, true)) {
            return $this->refused('run is outside the approved retranscription set ('.implode('/', self::ALLOWED_RUN_IDS).')');
        }

        $context = $run->historicStagingContext();

        if ($context === null) {
            return $this->refused('run has no historic staging context');
        }

        return $this->stagingContexts->within($context, function () use ($run, $execute): array {
            $freshRun = $run->fresh();

            if (! $freshRun instanceof MediaProcessingLog) {
                return $this->refused('run no longer exists');
            }

            $reason = $this->refusalReason($freshRun);

            if ($reason !== null) {
                return $this->refused($reason);
            }

            if (! $execute) {
                $replay = $freshRun->transcriptRecoveryReplay();

                return ['outcome' => 'ready', 'reason' => $replay === null
                    ? 'ready for full-service retranscription'
                    : sprintf('ready for full-service retranscription; will supersede the recovery replay stamp of %s', (string) ($replay['replayed_at'] ?? 'unknown date'))];
            }

            DB::transaction(function () use ($freshRun): void {
                $lockedRun = MediaProcessingLog::query()->lockForUpdate()->findOrFail($freshRun->id);
                $reason = $this->refusalReason($lockedRun);

                if ($reason !== null) {
                    throw new RuntimeException($reason);
                }

                $lockedRun->supersedeTranscriptRecoveryReplay('historic_retranscription');
                $lockedRun->refresh();
                $lockedRun->markAsReExtraction();

                if (! $this->transitions->markAsReopened($lockedRun, 'transcribe_full_service')) {
                    throw new RuntimeException('run could not be reopened');
                }

                $dispatchRun = $lockedRun->fresh();

                if (! $dispatchRun instanceof MediaProcessingLog) {
                    throw new RuntimeException('run disappeared before dispatch');
                }

                $this->orchestrator->start($dispatchRun, false);
            });

            return ['outcome' => 'dispatched', 'reason' => 'dispatched from full-service transcription'];
        });
    }

    private function refusalReason(MediaProcessingLog $run): ?string
    {
        if ($run->status !== ProcessingStatus::Completed) {
            return sprintf('run is %s, not completed', $run->status->value);
        }

        if ($run->processing_type !== MediaType::Livestream) {
            return 'run is not a livestream pipeline';
        }

        $operation = $run->historicImportOperation;
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $recordedOperationId = data_get($metadata, 'historic_import.operation_id');

        if (! is_string($recordedOperationId) || $operation === null || $recordedOperationId !== $operation->operation_id) {
            return 'run does not have matching historic operation ownership';
        }

        $anotherRunIsActive = MediaProcessingLog::query()
            ->whereKeyNot($run->id)
            ->whereIn('status', [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Started->value,
                ProcessingStatus::Processing->value,
            ])
            ->exists();

        if ($anotherRunIsActive) {
            return 'another run is active';
        }

        $sourcePath = $run->source_file_path;
        $disk = Storage::disk((string) config('media-processing.storage.temp_disk'));

        if (! is_string($sourcePath) || $sourcePath === '' || ! $disk->exists($sourcePath)) {
            return 'staged source is missing';
        }

        $expectedHash = $run->recordedSourceFileHash();

        if ($expectedHash === null) {
            return 'run has no recorded source hash';
        }

        $stream = $disk->readStream($sourcePath);

        if ($stream === null) {
            return 'staged source could not be read';
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actualHash = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if (! hash_equals($expectedHash, $actualHash)) {
            return 'staged source hash does not match recorded evidence';
        }

        return null;
    }

    /** @return array{outcome: 'refused', reason: string} */
    private function refused(string $reason): array
    {
        return ['outcome' => 'refused', 'reason' => $reason];
    }
}
