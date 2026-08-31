<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\HistoricWorkingCopyReachability;
use App\Support\Path;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reclaims historic sources once their review obligations have been resolved.
 *
 * A retained source is still a working copy. It is only eligible after the run
 * is terminal, its four review signals are clear, and M2's working-copy
 * reachability check has no queued, active or retryable reference to it.
 */
final class HistoricReviewSourceReclaimer
{
    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContextRegistry,
        private readonly VideoStorageService $storageService,
        private readonly HistoricWorkingCopyReachability $workingCopyReachability,
    ) {}

    /**
     * @return array{eligible: int, deleted: int, skipped: int, bytes: int}
     */
    public function sweep(bool $dryRun = false): array
    {
        $totals = [
            'eligible' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'bytes' => 0,
        ];

        MediaProcessingLog::query()
            ->whereNotNull('historic_import_operation_id')
            ->whereNotNull('source_file_path')
            ->orderBy('id')
            ->cursor()
            ->each(function (MediaProcessingLog $run) use (&$totals, $dryRun): void {
                if (! $this->isEligibleRun($run)) {
                    $totals['skipped']++;

                    return;
                }

                $context = $run->historicStagingContext();

                if (! $context instanceof HistoricStagingContext) {
                    $totals['skipped']++;

                    Log::warning('Skipped historic review-source reclamation without a staging context', [
                        'processing_id' => $run->processing_id,
                    ]);

                    return;
                }

                try {
                    $result = $this->stagingContextRegistry->within(
                        $context,
                        fn (): array => $this->reclaimRunSource($run, $dryRun),
                    );
                } catch (Throwable $exception) {
                    $totals['skipped']++;

                    Log::warning('Skipped historic review-source reclamation after storage validation failed', [
                        'processing_id' => $run->processing_id,
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }

                $totals['eligible'] += $result['eligible'];
                $totals['deleted'] += $result['deleted'];
                $totals['bytes'] += $result['bytes'];
            });

        return $totals;
    }

    private function isEligibleRun(MediaProcessingLog $run): bool
    {
        if (in_array($run->status, [
            ProcessingStatus::Pending,
            ProcessingStatus::Started,
            ProcessingStatus::Processing,
            ProcessingStatus::Failed,
        ], true)) {
            return false;
        }

        if ($run->hasUnresolvedReviewObligation()) {
            return false;
        }

        return $this->workingCopyReachability->unsettledWork($run) === null;
    }

    /**
     * @return array{eligible: int, deleted: int, bytes: int}
     */
    private function reclaimRunSource(MediaProcessingLog $run, bool $dryRun): array
    {
        $sourcePath = $run->source_file_path;

        if (! is_string($sourcePath) || $sourcePath === '' || Path::isUnsafe($sourcePath)) {
            return ['eligible' => 0, 'deleted' => 0, 'bytes' => 0];
        }

        $diskName = (string) config('media-processing.storage.temp_disk', 'local');
        $disk = Storage::disk($diskName);

        if (! $disk->exists($sourcePath)) {
            return ['eligible' => 0, 'deleted' => 0, 'bytes' => 0];
        }

        $bytes = $disk->size($sourcePath);

        if ($dryRun) {
            return ['eligible' => 1, 'deleted' => 0, 'bytes' => $bytes];
        }

        $this->storageService->cleanupTemporaryFiles([$sourcePath]);

        if (Storage::disk($diskName)->exists($sourcePath)) {
            Log::warning('Historic review source remained after reclamation attempt', [
                'processing_id' => $run->processing_id,
                'source_file_path' => $sourcePath,
            ]);

            return ['eligible' => 1, 'deleted' => 0, 'bytes' => 0];
        }

        return ['eligible' => 1, 'deleted' => 1, 'bytes' => $bytes];
    }
}
