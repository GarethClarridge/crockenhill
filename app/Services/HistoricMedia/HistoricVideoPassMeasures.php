<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\Sermon\SermonPromotionAssets;
use App\Support\Path;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
        private readonly HistoricStagingContextRegistry $stagingContextRegistry,
    ) {}

    /**
     * @param  list<string>  $itemKeys
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
    public function report(HistoricImportOperation $operation, array $itemKeys = []): array
    {
        $operationRuns = MediaProcessingLog::query()
            ->where('historic_import_operation_id', $operation->id)
            ->orderBy('id')
            ->get();
        $runs = $itemKeys === []
            ? $operationRuns
            : $operationRuns->filter(
                fn (MediaProcessingLog $run): bool => in_array($this->manifestItemKey($run), $itemKeys, true),
            )->values();

        $stagingContext = $this->stagingContextFor($operation, $operationRuns);

        return $this->stagingContextRegistry->within(
            $stagingContext,
            fn (): array => $this->reportWithinStagingContext($operationRuns, $runs, $itemKeys, $stagingContext),
        );
    }

    /**
     * @param  Collection<int, MediaProcessingLog>  $operationRuns
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @param  list<string>  $itemKeys
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
    private function reportWithinStagingContext(
        Collection $operationRuns,
        Collection $runs,
        array $itemKeys,
        HistoricStagingContext $stagingContext,
    ): array {

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
        $selectedPaths = $this->accountedStagingPaths($runs, $stagingContext);
        $accountedBytes = $this->bytesAtPaths($stagingSizes, $selectedPaths);
        $retainedBytes = $itemKeys === [] ? array_sum($stagingSizes) : $accountedBytes;
        $operationAccountedBytes = $this->bytesAtPaths(
            $stagingSizes,
            $this->accountedStagingPaths($operationRuns, $stagingContext),
        );

        return [
            'runs' => $runs->count(),
            'runs_reporting_promotion' => $runsReportingPromotion,
            'promoted_bytes' => $promotedBytes,
            'reclaimed_bytes' => $reclaimedBytes,
            'peak_working_bytes' => $peakWorkingBytes,
            'staging_retained_bytes' => $retainedBytes,
            'staging_accounted_bytes' => $accountedBytes,
            'unexplained_residue_bytes' => max(0, array_sum($stagingSizes) - $operationAccountedBytes),
            'quarantine_bytes' => $this->quarantineBytes($runs, $itemKeys === [], $stagingContext),
        ];
    }

    /**
     * Resolve the exact private root used by this operation. A historic run's
     * paths are relative to that root, so measuring the unscoped staging disk
     * would include prior batches and files outside historic processing.
     *
     * @param  Collection<int, MediaProcessingLog>  $runs
     */
    private function stagingContextFor(
        HistoricImportOperation $operation,
        Collection $runs,
    ): HistoricStagingContext {
        $contexts = [];
        $missingContextRunIds = [];

        foreach ($runs as $run) {
            $context = $run->historicStagingContext();

            if ($context instanceof HistoricStagingContext) {
                $contexts[] = $context;

                continue;
            }

            $missingContextRunIds[] = $run->processing_id;
        }

        if ($contexts === []) {
            $manifestHash = $this->operationManifestHash($operation);

            if ($manifestHash === null) {
                throw new RuntimeException('Historic custody measures require the operation manifest hash.');
            }

            return $this->staging->contextForApprovedPlan($manifestHash, $operation->plan_hash);
        }

        if ($missingContextRunIds !== []) {
            throw new RuntimeException(
                'Historic custody measures cannot mix runs with and without an approved staging context. '
                .'These runs carry none: '.implode(', ', $missingContextRunIds).'.'
            );
        }

        $context = $contexts[0];

        foreach (array_slice($contexts, 1) as $other) {
            if ($other->toArray() === $context->toArray()) {
                continue;
            }

            throw new RuntimeException(
                'Historic custody measures must select runs from one approved staging context.'
            );
        }

        $this->assertOperationContext($operation, $context);

        return $context;
    }

    private function assertOperationContext(
        HistoricImportOperation $operation,
        HistoricStagingContext $context,
    ): void {
        if (! hash_equals($operation->plan_hash, $context->planHash)) {
            throw new RuntimeException(
                'Historic custody measures staging context does not match the operation plan hash.'
            );
        }

        $manifestHash = $this->operationManifestHash($operation);

        if ($manifestHash !== null && ! hash_equals($manifestHash, $context->manifestHash)) {
            throw new RuntimeException(
                'Historic custody measures staging context does not match the operation manifest hash.'
            );
        }
    }

    private function operationManifestHash(HistoricImportOperation $operation): ?string
    {
        foreach (['historic_video', 'video'] as $sourceKind) {
            $manifestHash = $operation->manifest_hashes[$sourceKind] ?? null;

            if (is_string($manifestHash) && $manifestHash !== '') {
                return $manifestHash;
            }
        }

        return count($operation->manifest_hashes) === 1
            ? array_values($operation->manifest_hashes)[0]
            : null;
    }

    private function manifestItemKey(MediaProcessingLog $run): ?string
    {
        $itemKey = data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key');

        return is_string($itemKey) && $itemKey !== '' ? $itemKey : null;
    }

    /**
     * @param  array<string, int>  $sizes
     * @param  list<string>  $paths
     */
    private function bytesAtPaths(array $sizes, array $paths): int
    {
        $bytes = 0;

        foreach ($paths as $path) {
            $bytes += $sizes[$path] ?? 0;
        }

        return $bytes;
    }

    /** @param Collection<int, MediaProcessingLog> $runs */
    private function quarantineBytes(
        Collection $runs,
        bool $entireDisk,
        HistoricStagingContext $stagingContext,
    ): int {
        $sizes = $this->fileSizes($this->quarantineDisk());

        if ($entireDisk) {
            return array_sum($sizes);
        }

        return $this->bytesAtPaths($sizes, array_values(array_unique(array_merge(
            $this->sermonAssetPaths($runs, $stagingContext),
            $this->songVideoPaths($runs),
        ))));
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
    private function accountedStagingPaths(
        Collection $runs,
        HistoricStagingContext $stagingContext,
    ): array {
        $paths = [];

        foreach ($runs as $run) {
            foreach ($run->temporaryFilePaths() as $path) {
                $normalizedPath = $this->pathWithinActiveStaging($path, $stagingContext);

                if ($normalizedPath !== null) {
                    $paths[] = $normalizedPath;
                }
            }

            foreach ($this->runOwnedStagingPaths($run, $stagingContext) as $path) {
                $paths[] = $path;
            }

            foreach ($this->sermonAssetPaths(collect([$run]), $stagingContext) as $path) {
                $paths[] = $path;
            }

            foreach ($this->songVideoStagingPaths(collect([$run]), $stagingContext) as $path) {
                $paths[] = $path;
            }

            foreach ($this->sectionCandidateStagingPaths(collect([$run]), $stagingContext) as $path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Service artifacts are deliberately retained across cleanup, so they are
     * still accounted staging output even though they are not temporary files.
     * Older runs only carried the RMS path column; newer artifact writers also
     * record the disk and path in the service-artifacts metadata list.
     *
     * @return list<string>
     */
    private function runOwnedStagingPaths(
        MediaProcessingLog $run,
        HistoricStagingContext $stagingContext,
    ): array {
        $paths = [];
        $rmsPath = $this->pathWithinActiveStaging($run->rms_log_path ?? '', $stagingContext);

        if ($rmsPath !== null) {
            $paths[] = $rmsPath;
        }

        $artifacts = data_get($run->processing_metadata?->toArray(), 'service_artifacts');

        if (! is_array($artifacts)) {
            return $paths;
        }

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact) || ($artifact['disk'] ?? null) !== $stagingContext->stagingDisk) {
                continue;
            }

            $path = $artifact['path'] ?? null;

            if (! is_string($path)) {
                continue;
            }

            $normalizedPath = $this->pathWithinActiveStaging($path, $stagingContext);

            if ($normalizedPath !== null) {
                $paths[] = $normalizedPath;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return list<string>
     */
    private function sermonAssetPaths(
        Collection $runs,
        HistoricStagingContext $stagingContext,
    ): array {
        $paths = [];

        foreach ($runs as $run) {
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
                    $normalizedPath = $this->pathWithinActiveStaging($reference['path'], $stagingContext);

                    if ($normalizedPath !== null) {
                        $paths[] = $normalizedPath;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Song video rows are linked to a run through their service section. Their
     * stored paths are already relative to the quarantine disk when promoted,
     * so this raw form is also what the selected quarantine measure needs.
     *
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return list<string>
     */
    private function songVideoPaths(Collection $runs): array
    {
        $runIds = array_values(array_filter(
            $runs->pluck('id')->all(),
            static fn (mixed $id): bool => is_int($id),
        ));

        if ($runIds === []) {
            return [];
        }

        $uniquePaths = [];

        foreach (SongVideo::query()
            ->whereHas('serviceSection', function (Builder $query) use ($runIds): void {
                $query->whereIn('media_processing_log_id', $runIds);
            })
            ->pluck('video_file_path') as $path) {
            if (is_string($path) && $path !== '') {
                $uniquePaths[$path] = true;
            }
        }

        return array_keys($uniquePaths);
    }

    /**
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return list<string>
     */
    private function songVideoStagingPaths(
        Collection $runs,
        HistoricStagingContext $stagingContext,
    ): array {
        $paths = [];

        foreach ($this->songVideoPaths($runs) as $path) {
            $normalizedPath = $this->pathWithinActiveStaging($path, $stagingContext);

            if ($normalizedPath !== null) {
                $paths[] = $normalizedPath;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * A pending song or children's-talk section owns its extracted candidate
     * until the review obligation is resolved. Include every section candidate
     * here because a retained path can be shared with a SongVideo or with a
     * published section and the byte measure must not count it twice.
     *
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return list<string>
     */
    private function sectionCandidateStagingPaths(
        Collection $runs,
        HistoricStagingContext $stagingContext,
    ): array {
        $runIds = array_values(array_filter(
            $runs->pluck('id')->all(),
            static fn (mixed $id): bool => is_int($id),
        ));

        if ($runIds === []) {
            return [];
        }

        $paths = [];
        $sections = ServiceSection::query()
            ->whereIn('media_processing_log_id', $runIds)
            ->where('publication_status', ServiceSectionPublicationStatus::PendingApproval->value)
            ->where(function (Builder $query): void {
                $query->whereNotNull('extracted_video_path')
                    ->orWhereNotNull('extracted_audio_path');
            })
            ->get(['extracted_video_path', 'extracted_audio_path', 'publication_status']);

        foreach ($sections as $section) {
            foreach ([$section->extracted_video_path, $section->extracted_audio_path] as $path) {
                if (! is_string($path) || $path === '') {
                    continue;
                }

                $normalizedPath = $this->pathWithinActiveStaging($path, $stagingContext);

                if ($normalizedPath !== null) {
                    $paths[] = $normalizedPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function pathWithinActiveStaging(
        string $path,
        HistoricStagingContext $stagingContext,
    ): ?string {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || Path::isUnsafe($path)) {
            return null;
        }

        $batchRoot = trim(str_replace('\\', '/', $stagingContext->batchRoot), '/');

        if ($path === $batchRoot) {
            return null;
        }

        if (str_starts_with($path, "{$batchRoot}/")) {
            return substr($path, strlen($batchRoot) + 1);
        }

        return ltrim($path, '/');
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
