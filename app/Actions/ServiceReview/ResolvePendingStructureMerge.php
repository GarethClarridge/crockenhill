<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\StructureMergeResolution;
use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Services\ChurchService\ChurchServiceCanonicalStateService;
use App\Services\ChurchService\ChurchServiceCanonicalUpdateService;
use App\Services\ChurchService\ChurchServiceItemSyncService;
use App\Services\ChurchService\ChurchServiceReviewStateService;
use App\Services\ChurchService\SourceAdapters\ManualSourceAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResolvePendingStructureMerge
{
    public function __construct(
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ChurchServiceReviewStateService $reviewStateService,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly ManualSourceAdapter $manualSourceAdapter,
    ) {}

    /**
     * Resolve a pending structure merge by accepting the incoming items or keeping the current ones.
     */
    public function execute(
        ChurchService $churchService,
        string $resolution,
        int $userId,
        ?int $expectedCanonicalRevision = null,
    ): StructureMergeResolution {
        return DB::transaction(function () use ($churchService, $resolution, $userId, $expectedCanonicalRevision): StructureMergeResolution {
            $lockedService = ChurchService::query()
                ->whereKey($churchService->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $loadedRevision = $expectedCanonicalRevision ?? $churchService->canonical_revision;

            if ($lockedService->canonical_revision !== $loadedRevision) {
                return new StructureMergeResolution(
                    churchService: $lockedService,
                    resolution: $resolution,
                    applied: false,
                    reason: 'This service changed since you opened it. Reload the page and recompute the proposal before resolving it.',
                );
            }

            $pendingMerge = $lockedService->import_metadata?->pendingStructureMerge;
            $incomingSource = $lockedService->pending_structure_merge_source;

            if (blank($pendingMerge) || blank($incomingSource)) {
                return new StructureMergeResolution(
                    churchService: $lockedService,
                    resolution: $resolution,
                    applied: false,
                    reason: 'No pending structure merge found',
                );
            }

            $incomingSource = trim($incomingSource);

            return match ($resolution) {
                'accept_incoming' => $this->acceptIncoming($lockedService, $pendingMerge->proposedItems, $incomingSource, $userId),
                'keep_current' => $this->keepCurrent($lockedService, $userId),
                default => new StructureMergeResolution(
                    churchService: $lockedService,
                    resolution: $resolution,
                    applied: false,
                    reason: 'Unknown resolution: '.$resolution,
                ),
            };
        });
    }

    /**
     * @param  list<array<string, mixed>>  $proposedItems
     */
    private function acceptIncoming(
        ChurchService $churchService,
        array $proposedItems,
        string $incomingSourceValue,
        int $userId,
    ): StructureMergeResolution {
        $incomingSource = ChurchServiceItemSource::tryFrom($incomingSourceValue) ?? ChurchServiceItemSource::Manual;

        $beforeSnapshot = $this->canonicalStateService->snapshot($churchService);

        $syncResult = $this->itemSyncService->sync($churchService, $proposedItems, $incomingSource, [
            'replace_mode' => true,
        ]);

        $churchService = $this->canonicalUpdateService->finalize(
            $churchService,
            $beforeSnapshot,
            $incomingSource,
            $syncResult,
        );

        // Update service source to reflect the accepted incoming source now that items
        // have been applied — this was intentionally deferred during the initial import.
        $churchService->forceFill(['source' => $incomingSource->value])->saveQuietly();

        // Only preserve needs_review=true when finalize specifically reopened review
        // (i.e. a previously-reviewed service was changed). finalize signals this by
        // setting manual_review.reopened_at. For a normal accept, needs_review is cleared.
        $importMetadata = $churchService->import_metadata?->toArray() ?? [];
        $reviewReopened = isset($importMetadata['manual_review']['reopened_at']);

        $this->clearPendingMerge($churchService, 'accept_incoming', $userId, preserveNeedsReview: $reviewReopened);
        $churchService = $this->ingestManualRevision($churchService, $proposedItems, $userId);

        Log::info('Pending structure merge resolved: accepted incoming', [
            'church_service_id' => $churchService->id,
            'incoming_source' => $incomingSourceValue,
            'resolved_by_user_id' => $userId,
            'items_synced' => count($proposedItems),
        ]);

        return new StructureMergeResolution(
            churchService: $churchService,
            resolution: 'accept_incoming',
            applied: true,
            reason: 'Incoming '.$incomingSourceValue.' items applied and canonical list updated',
        );
    }

    private function keepCurrent(
        ChurchService $churchService,
        int $userId,
    ): StructureMergeResolution {
        $incomingSource = is_string($churchService->pending_structure_merge_source)
            ? $churchService->pending_structure_merge_source
            : 'unknown';

        $this->clearPendingMerge($churchService, 'keep_current', $userId, preserveNeedsReview: false);
        $items = $churchService->items()
            ->orderBy('position')
            ->get()
            ->map(fn ($item): array => $item->only([
                'position',
                'type',
                'section_type',
                'title',
                'source_title',
                'song_id',
                'metadata',
            ]))
            ->all();
        $churchService = $this->ingestManualRevision($churchService, $items, $userId);

        Log::info('Pending structure merge resolved: kept current', [
            'church_service_id' => $churchService->id,
            'incoming_source' => $incomingSource,
            'resolved_by_user_id' => $userId,
        ]);

        return new StructureMergeResolution(
            churchService: $churchService,
            resolution: 'keep_current',
            applied: true,
            reason: 'Current canonical items preserved; incoming '.$incomingSource.' data discarded',
        );
    }

    /**
     * Clear the pending merge metadata and record the resolution decision.
     *
     * When $preserveNeedsReview is true, needs_review stays true — used when finalize
     * detected that a previously-reviewed service was changed and reopened review.
     * For keep_current (no items change) and normal accepts, needs_review is cleared.
     */
    private function clearPendingMerge(
        ChurchService $churchService,
        string $resolution,
        int $userId,
        bool $preserveNeedsReview,
    ): void {
        $importMetadata = $churchService->import_metadata?->toArray() ?? [];

        $importMetadata['structure_merge_resolution'] = [
            'resolution' => $resolution,
            'resolved_at' => now()->toIso8601String(),
            'resolved_by_user_id' => $userId,
            'original_incoming_source' => $churchService->pending_structure_merge_source,
            'conflict_count' => count($importMetadata['pending_structure_merge']['conflicts'] ?? []),
        ];

        unset($importMetadata['pending_structure_merge']);

        $importMetadata['manual_review'] = [
            'reviewed_at' => now()->toIso8601String(),
            'reviewed_by_user_id' => $userId,
        ];

        $normalizedColumns = $this->reviewStateService->normalizedReviewColumns($importMetadata);

        $churchService->forceFill([
            'needs_review' => $preserveNeedsReview,
            'review_reason' => $preserveNeedsReview ? $churchService->review_reason : null,
            'pending_structure_merge_source' => null,
            'import_metadata' => $importMetadata,
            ...$normalizedColumns,
        ])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function ingestManualRevision(ChurchService $churchService, array $items, int $userId): ChurchService
    {
        $this->ingestSourceRevision->execute(
            $churchService,
            $this->manualSourceAdapter->adapt(
                $items,
                $userId,
                [
                    'summary' => $churchService->summary,
                    'notices' => $churchService->notices,
                    'chapter_markers' => $churchService->chapter_markers,
                ],
            ),
        );

        $churchService = $churchService->fresh() ?? $churchService;
        $churchService->forceFill([
            'reviewed_canonical_revision' => $churchService->canonical_revision,
            'source_summary' => 'manual',
            'source' => ChurchServiceItemSource::Manual->value,
        ])->saveQuietly();

        return $churchService;
    }
}
