<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricAssetPromotion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Move a historic run's durable outputs off working staging before cleanup runs.
 *
 * This sits immediately before {@see CleanupTemporaryFiles} in every pipeline
 * because the two are one custody transition read in order: cleanup is only
 * entitled to delete a working copy whose durable destination has been verified,
 * and this job is what verifies it. Keeping them as separate jobs keeps their
 * retry semantics apart — a failed promotion must be retryable without
 * re-running a cleanup that already succeeded, and cleanup runs `tries = 1`.
 *
 * Non-historic runs are a no-op. There is nowhere for an ordinary livestream's
 * output to be promoted to: it is already written to its permanent disk.
 *
 * Deletion trigger: Delete after IC8 closeout, alongside the historic-video
 * dispatcher and once every historic asset has been released or discarded.
 */
class PromoteHistoricAssets extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    /**
     * Retry a stalled or failed promotion with room for the copy to finish.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(HistoricAssetPromotion $promotion): void
    {
        $processingLog = $this->processingLog->fresh();

        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;
        $this->initializeStepLogging($processingLog->processing_id);

        if ($processingLog->historic_import_operation_id === null) {
            return;
        }

        if ($this->isCancelled()) {
            Log::info('PromoteHistoricAssets job cancelled', [
                'processing_id' => $processingLog->processing_id,
            ]);

            return;
        }

        $this->logStepStart('promoting_historic_assets', 'Promoting historic assets to private quarantine');

        $totals = $promotion->promoteRun($processingLog);

        $this->recordMeasures($processingLog, $totals);

        $this->logStepComplete(
            'promoting_historic_assets',
            "Promoted {$totals['assets_promoted']} asset(s) across {$totals['sermons']} sermon(s) to private quarantine",
        );

        Log::info('Historic assets promoted to private quarantine', [
            'processing_id' => $processingLog->processing_id,
            'historic_import_operation_id' => $processingLog->historic_import_operation_id,
            ...$totals,
        ]);
    }

    /**
     * Persist the byte movement this run accounted for.
     *
     * The pass-level measures the canary reports are summed from these, so they
     * have to survive the run rather than living only in a log line.
     *
     * @param  array{
     *     sermons: int,
     *     assets_promoted: int,
     *     assets_already_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int,
     *     staging_bytes_before_reclaim: int
     * }  $totals
     */
    private function recordMeasures(MediaProcessingLog $processingLog, array $totals): void
    {
        $processingLog->update([
            'processing_metadata' => array_merge(
                $processingLog->processing_metadata?->toArray() ?? [],
                ['historic_promotion' => $totals],
            ),
        ]);
    }
}
