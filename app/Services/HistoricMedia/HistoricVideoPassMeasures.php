<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Sermon\SermonPromotionAssets;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The four byte measures Phase 5 requires the canary to report.
 *
 * Peak working bytes, bytes promoted to private quarantine, bytes retained on
 * staging after promotion, and unexplained residue. Together they are what
 * decides whether the operation needs specialised reclamation at all: the plan's
 * §2.1 capacity premise turned out to be a `df` artefact, so no reclamation
 * architecture should be built until measured residue justifies one.
 *
 * Two of the measures are database-owned and two are observed:
 *
 * - `promoted_bytes` and `peak_working_bytes` are summed and maximised from what
 *   {@see HistoricAssetPromotion} recorded on each run. They cannot disagree
 *   with what the operation actually did.
 * - `staging_retained_bytes` and `quarantine_bytes` are walked live, because the
 *   question they answer is what is on the disk *now*, which no record can be
 *   trusted to know.
 *
 * `unexplained_residue_bytes` is the gap between them: staging bytes that no run
 * of this operation can account for, either as a working copy it still owns or
 * as durable output not yet promoted. That number, not an estimate, is what a
 * later cleanup change would have to name as its justification.
 *
 * Deletion trigger: Delete after IC8 closeout, alongside the historic-video
 * dispatcher and after the final operation report is retained.
 */
class HistoricVideoPassMeasures
{
    public function __construct(
        private readonly SermonPromotionAssets $assets,
        private readonly HistoricStagingGuard $staging,
    ) {}

    /**
     * @return array{
     *     runs: int,
     *     runs_reporting_promotion: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int,
     *     peak_working_bytes: int,
     *     staging_retained_bytes: int,
     *     staging_accounted_bytes: int,
     *     unexplained_residue_bytes: int,
     *     quarantine_bytes: int
     * }
     */
    public function report(HistoricImportOperation $operation): array
    {
        $runs = MediaProcessingLog::query()
            ->where('historic_import_operation_id', $operation->id)
            ->orderBy('id')
            ->get();

        $promotedBytes = 0;
        $reclaimedBytes = 0;
        $peakWorkingBytes = 0;
        $runsReportingPromotion = 0;

        foreach ($runs as $run) {
            $promotion = data_get($run->processing_metadata?->toArray(), 'historic_promotion');

            if (! is_array($promotion)) {
                continue;
            }

            $runsReportingPromotion++;
            $promotedBytes += (int) ($promotion['promoted_bytes'] ?? 0);
            $reclaimedBytes += (int) ($promotion['reclaimed_bytes'] ?? 0);
            $peakWorkingBytes = max($peakWorkingBytes, (int) ($promotion['staging_bytes_before_reclaim'] ?? 0));
        }

        $stagingSizes = $this->fileSizes($this->staging->stagingDisk());
        $accountedPaths = $this->accountedStagingPaths($runs);

        $accountedBytes = 0;

        foreach ($accountedPaths as $path) {
            $accountedBytes += $stagingSizes[$path] ?? 0;
        }

        $retainedBytes = array_sum($stagingSizes);

        return [
            'runs' => $runs->count(),
            'runs_reporting_promotion' => $runsReportingPromotion,
            'promoted_bytes' => $promotedBytes,
            'reclaimed_bytes' => $reclaimedBytes,
            'peak_working_bytes' => $peakWorkingBytes,
            'staging_retained_bytes' => $retainedBytes,
            'staging_accounted_bytes' => $accountedBytes,
            'unexplained_residue_bytes' => max(0, $retainedBytes - $accountedBytes),
            'quarantine_bytes' => array_sum($this->fileSizes($this->quarantineDisk())),
        ];
    }

    /**
     * Every staging path some run of this operation can explain.
     *
     * A run explains a path when it is one of its declared working copies, or
     * when it is durable output of a sermon the run owns that has not been
     * promoted yet. Anything else on staging is residue by definition.
     *
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return list<string>
     */
    private function accountedStagingPaths(Collection $runs): array
    {
        $paths = [];

        foreach ($runs as $run) {
            foreach ($run->temporaryFilePaths() as $path) {
                $paths[] = $path;
            }

            $sermons = Sermon::query()
                ->where(function ($query) use ($run): void {
                    $query->where('livestream_processing_id', $run->processing_id);

                    if ($run->sermon_id !== null) {
                        $query->orWhere('id', $run->sermon_id);
                    }
                })
                ->get();

            foreach ($sermons as $sermon) {
                foreach ($this->assets->referencesForSermon($sermon) as $reference) {
                    $paths[] = $reference['path'];
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Path => byte size for every file on a disk.
     *
     * Metadata only: sizes come from the filesystem's own records, so no asset
     * content is read to produce a measure.
     *
     * @return array<string, int>
     */
    private function fileSizes(string $disk): array
    {
        $adapter = Storage::disk($disk);
        $sizes = [];

        foreach ($adapter->allFiles() as $path) {
            $sizes[$path] = $adapter->size($path);
        }

        return $sizes;
    }

    private function quarantineDisk(): string
    {
        return (string) config('media-processing.storage.historic_quarantine_disk', '');
    }
}
