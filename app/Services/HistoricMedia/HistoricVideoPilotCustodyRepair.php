<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\SermonSourceType;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Sermon\SermonPromotionAssets;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Validate and repair the direct historic-video pilot's old disk custody.
 *
 * The pilot predates {@see HistoricAssetPromotion}, so its completed sermons
 * can still be published rows whose files are on the removable staging disk.
 * This service keeps the exceptional repair narrow: the caller must name one
 * immutable operation and every processing ID explicitly, all rows and asset
 * references are checked before any database write, and the existing
 * create-only promotion service owns the byte transfer.
 *
 * Deletion trigger: Delete after the historic-video operation reaches IC8
 * closeout and the pilot custody repair is retained in its closeout evidence.
 */
final class HistoricVideoPilotCustodyRepair
{
    /**
     * @param  list<string>  $processingIds
     * @return list<array{
     *     processing_id: string,
     *     log: MediaProcessingLog,
     *     sermon: Sermon,
     *     disposition: 'pending'|'already_repaired',
     *     asset_count: int,
     *     staged_bytes: int
     * }>
     */
    public function inspect(HistoricImportOperation $operation, array $processingIds): array
    {
        if ($processingIds === []) {
            throw new RuntimeException('At least one exact completed pilot --processing-id is required.');
        }

        if (count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('Each pilot --processing-id must be listed exactly once.');
        }

        $this->staging->assertLocalProcessingIsIsolated();

        $runs = MediaProcessingLog::query()
            ->with('sermon')
            ->whereIn('processing_id', $processingIds)
            ->get()
            ->keyBy('processing_id');

        $missing = array_values(array_diff($processingIds, $runs->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException(
                'Every selected processing ID must exist; missing: '.implode(', ', $missing).'.'
            );
        }

        $context = $this->stagingContextForRuns($operation, $runs);

        return $this->stagingContextRegistry->within(
            $context,
            function () use ($operation, $processingIds, $runs): array {
                $entries = [];

                foreach ($processingIds as $processingId) {
                    $run = $runs->get($processingId);

                    if (! $run instanceof MediaProcessingLog) {
                        throw new RuntimeException("Processing run {$processingId} could not be loaded.");
                    }

                    $this->assertRunBelongsToOperation($run, $operation);
                    $sermon = $this->sermonForRun($run);
                    $references = $this->assets->referencesForSermon($sermon);

                    if ($references === []) {
                        throw new RuntimeException("Sermon {$sermon->id} has no durable assets to repair.");
                    }

                    $assetState = $this->assetState($sermon, $references, $operation);
                    $disposition = $this->disposition($sermon, $assetState, $operation);

                    $entries[] = [
                        'processing_id' => $processingId,
                        'log' => $run,
                        'sermon' => $sermon,
                        'disposition' => $disposition,
                        'asset_count' => count($references),
                        'staged_bytes' => $assetState['staged_bytes'],
                    ];
                }

                return $entries;
            },
        );
    }

    /**
     * Quarantine every pending sermon before asking the existing promotion
     * service to copy anything. A failed copy therefore leaves the record
     * private and the staging source available for a subsequent retry.
     *
     * @param  list<array{
     *     processing_id: string,
     *     log: MediaProcessingLog,
     *     sermon: Sermon,
     *     disposition: 'pending'|'already_repaired',
     *     asset_count: int,
     *     staged_bytes: int
     * }>  $entries
     * @return array{
     *     repaired: int,
     *     already_repaired: int,
     *     assets_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int
     * }
     */
    public function apply(HistoricImportOperation $operation, array $entries): array
    {
        $runs = new Collection(array_map(
            static fn (array $entry): MediaProcessingLog => $entry['log'],
            $entries,
        ));
        $context = $this->stagingContextForRuns($operation, $runs);

        return $this->stagingContextRegistry->within(
            $context,
            fn (): array => $this->applyWithinStagingContext($operation, $entries),
        );
    }

    /**
     * @param  list<array{
     *     processing_id: string,
     *     log: MediaProcessingLog,
     *     sermon: Sermon,
     *     disposition: 'pending'|'already_repaired',
     *     asset_count: int,
     *     staged_bytes: int
     * }>  $entries
     * @return array{
     *     repaired: int,
     *     already_repaired: int,
     *     assets_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int
     * }
     */
    private function applyWithinStagingContext(HistoricImportOperation $operation, array $entries): array
    {
        $pending = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['disposition'] === 'pending',
        ));

        DB::transaction(function () use ($operation, $pending): void {
            foreach ($pending as $entry) {
                $sermon = Sermon::query()->lockForUpdate()->find($entry['sermon']->id);

                if (! $sermon instanceof Sermon) {
                    throw new RuntimeException("Sermon {$entry['sermon']->id} disappeared before custody repair.");
                }

                $this->assertCanQuarantine($sermon, $operation);

                if ($sermon->publication_state !== SermonPublicationState::Quarantined) {
                    $sermon->forceFill([
                        'publication_state' => SermonPublicationState::Quarantined,
                    ])->save();
                }
            }
        });

        $totals = [
            'repaired' => 0,
            'already_repaired' => count($entries) - count($pending),
            'assets_promoted' => 0,
            'promoted_bytes' => 0,
            'reclaimed_bytes' => 0,
        ];

        foreach ($pending as $entry) {
            $run = $entry['log']->fresh();

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException("Processing run {$entry['processing_id']} disappeared during custody repair.");
            }

            $this->assertRunBelongsToOperation($run, $operation);
            $this->stagingContextForRuns($operation, new Collection([$run]));
            $result = $this->promotion->promoteRun($run);
            $totals['repaired']++;
            $totals['assets_promoted'] += $result['assets_promoted'];
            $totals['promoted_bytes'] += $result['promoted_bytes'];
            $totals['reclaimed_bytes'] += $result['reclaimed_bytes'];
        }

        return $totals;
    }

    public function __construct(
        private readonly HistoricAssetPromotion $promotion,
        private readonly HistoricProcessingResultAssetTransfer $transfer,
        private readonly HistoricStagingGuard $staging,
        private readonly HistoricStagingContextRegistry $stagingContextRegistry,
        private readonly SermonPromotionAssets $assets,
    ) {}

    /** @param Collection<int, MediaProcessingLog> $runs */
    private function stagingContextForRuns(
        HistoricImportOperation $operation,
        Collection $runs,
    ): HistoricStagingContext {
        $contexts = $runs
            ->map(static fn (MediaProcessingLog $run): ?HistoricStagingContext => $run->historicStagingContext());

        if ($contexts->contains(null)) {
            throw new RuntimeException('Every selected pilot run must record its approved historic staging context.');
        }

        $context = $contexts->first();

        if (! $context instanceof HistoricStagingContext) {
            throw new RuntimeException('The selected pilot runs have no historic staging context.');
        }

        foreach ($contexts as $other) {
            if (! $other instanceof HistoricStagingContext || $other->toArray() !== $context->toArray()) {
                throw new RuntimeException('Every selected pilot run must use the same historic staging context.');
            }
        }

        $manifestHash = $operation->manifest_hashes['historic_video']
            ?? $operation->manifest_hashes['video']
            ?? null;

        if (! is_string($manifestHash)
            || ! hash_equals($manifestHash, $context->manifestHash)
            || ! hash_equals($operation->plan_hash, $context->planHash)) {
            throw new RuntimeException('The pilot staging context does not match the named operation manifest and plan.');
        }

        return $context;
    }

    private function assertRunBelongsToOperation(
        MediaProcessingLog $run,
        HistoricImportOperation $operation,
    ): void {
        if ($run->status !== ProcessingStatus::Completed) {
            throw new RuntimeException("Processing run {$run->processing_id} must be completed.");
        }

        if ($run->processing_type !== MediaType::Livestream) {
            throw new RuntimeException("Processing run {$run->processing_id} is not a historic livestream run.");
        }

        if ($run->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} does not belong to the named historic operation."
            );
        }

        $metadata = $run->processing_metadata?->toArray() ?? [];
        $historicImport = $metadata['historic_import'] ?? null;
        $recordedOperationId = is_array($historicImport) ? ($historicImport['operation_id'] ?? null) : null;

        if (! is_array($historicImport) || ! is_string($run->historicImportJobKey())) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} is not an operation-bound historic run."
            );
        }

        if ($recordedOperationId !== $operation->operation_id) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} metadata operation identity does not match the named historic operation."
            );
        }
    }

    private function sermonForRun(MediaProcessingLog $run): Sermon
    {
        $linked = $run->sermon;
        $sermons = Sermon::query()
            ->where('livestream_processing_id', $run->processing_id)
            ->orderBy('id')
            ->get();

        if ($sermons->count() !== 1) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} must identify exactly one livestream sermon."
            );
        }

        $sermon = $sermons->first();

        if (! $sermon instanceof Sermon) {
            throw new RuntimeException("Processing run {$run->processing_id} has no livestream sermon.");
        }

        if ($run->sermon_id !== null && $run->sermon_id !== $sermon->id) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} sermon link does not match its livestream sermon."
            );
        }

        if ($linked instanceof Sermon && $linked->id !== $sermon->id) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} has a foreign sermon link."
            );
        }

        if ($sermon->source_type !== SermonSourceType::Livestream) {
            throw new RuntimeException(
                "Sermon {$sermon->id} is curated or manually owned, not a historic livestream output."
            );
        }

        return $sermon;
    }

    /**
     * @param  list<array{kind: string, path: string}>  $references
     * @return array{staged_bytes: int, staged_count: int, quarantine_count: int, reference_count: int}
     */
    private function assetState(
        Sermon $sermon,
        array $references,
        HistoricImportOperation $operation,
    ): array {
        $staging = Storage::disk($this->staging->stagingDisk());
        $quarantine = Storage::disk($this->transfer->targetDiskName());
        $stagedBytes = 0;
        $stagedCount = 0;
        $quarantineCount = 0;

        foreach ($references as $reference) {
            $path = $reference['path'];
            $onStaging = $staging->exists($path);
            $onQuarantine = $quarantine->exists($path);

            if (! $onStaging && ! $onQuarantine) {
                throw new RuntimeException(
                    "Sermon {$sermon->id} asset {$reference['kind']} is missing from historic staging and quarantine."
                );
            }

            if ($onStaging) {
                $stagedCount++;
                $stagedBytes += $staging->size($path);
            }

            if ($onQuarantine) {
                $quarantineCount++;
            }
        }

        if ($sermon->asset_disk !== null
            && $sermon->asset_disk !== $this->transfer->targetDiskName()) {
            throw new RuntimeException(
                "Sermon {$sermon->id} is already owned by disk {$sermon->asset_disk}; refusing a foreign custody repair."
            );
        }

        if ($sermon->historic_import_operation_id !== null
            && $sermon->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException(
                "Sermon {$sermon->id} is already owned by a different historic operation."
            );
        }

        return [
            'staged_bytes' => $stagedBytes,
            'staged_count' => $stagedCount,
            'quarantine_count' => $quarantineCount,
            'reference_count' => count($references),
        ];
    }

    /**
     * @param  array{staged_bytes: int, staged_count: int, quarantine_count: int, reference_count: int}  $assetState
     * @return 'pending'|'already_repaired'
     */
    private function disposition(
        Sermon $sermon,
        array $assetState,
        HistoricImportOperation $operation,
    ): string {
        $target = $this->transfer->targetDiskName();
        $fullyOwned = $sermon->publication_state === SermonPublicationState::Quarantined
            && $sermon->asset_disk === $target
            && $sermon->historic_import_operation_id === $operation->id;

        if ($fullyOwned && $assetState['staged_count'] === 0) {
            return 'already_repaired';
        }

        if ($sermon->publication_state === SermonPublicationState::Published
            && $sermon->asset_disk !== null) {
            throw new RuntimeException(
                "Sermon {$sermon->id} is published with an existing disk owner; refusing an inconsistent custody repair."
            );
        }

        if ($sermon->publication_state === SermonPublicationState::Published
            && $assetState['quarantine_count'] > 0
            && $assetState['staged_count'] !== $assetState['reference_count']) {
            throw new RuntimeException(
                "Sermon {$sermon->id} has quarantine bytes without a complete staging source set; refusing an unverifiable custody repair."
            );
        }

        if ($sermon->publication_state === SermonPublicationState::Quarantined
            && $sermon->asset_disk !== null
            && $sermon->asset_disk !== $target) {
            throw new RuntimeException("Sermon {$sermon->id} has an inconsistent quarantine disk binding.");
        }

        return 'pending';
    }

    private function assertCanQuarantine(Sermon $sermon, HistoricImportOperation $operation): void
    {
        if ($sermon->source_type !== SermonSourceType::Livestream) {
            throw new RuntimeException(
                "Sermon {$sermon->id} is curated or manually owned, not a historic livestream output."
            );
        }

        if ($sermon->historic_import_operation_id !== null
            && $sermon->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException("Sermon {$sermon->id} is already owned by a different historic operation.");
        }

        $target = $this->transfer->targetDiskName();

        if ($sermon->asset_disk !== null && $sermon->asset_disk !== $target) {
            throw new RuntimeException("Sermon {$sermon->id} is already owned by disk {$sermon->asset_disk}.");
        }
    }
}
