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
    ): StructureMergeResolution {
        $pendingMerge = $churchService->import_metadata?->pendingStructureMerge;
        $incomingSource = $churchService->pending_structure_merge_source;

        if (blank($pendingMerge) || blank($incomingSource)) {
            return new StructureMergeResolution(
                churchService: $churchService,
                resolution: $resolution,
                applied: false,
                reason: 'No pending structure merge found',
            );
        }

        $incomingSource = trim($incomingSource);

        return DB::transaction(fn (): StructureMergeResolution => match ($resolution) {
            'accept_incoming' => $this->acceptIncoming($churchService, $pendingMerge->proposedItems, $incomingSource, $userId),
            'keep_current' => $this->keepCurrent($churchService, $userId),
            default => new StructureMergeResolution(
                churchService: $churchService,
                resolution: $resolution,
                applied: false,
                reason: 'Unknown resolution: '.$resolution,
            ),
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
        $this->ingestManualRevision($churchService, $proposedItems, $userId);

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
        $this->ingestManualRevision($churchService, $items, $userId);

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
    private function ingestManualRevision(ChurchService $churchService, array $items, int $userId): void
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
    }
}
