<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\StructureMergeResult;
use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChurchServiceStructureMergeService
{
    public function __construct(
        private readonly StructureMergePolicy $policy,
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ServiceItemCatalogueSongResolver $catalogueSongResolver,
    ) {}

    /**
     * Evaluate and execute a merge of incoming items into an existing service.
     *
     * When the existing service contains high-confidence livestream-derived items
     * and the incoming source is a different authority, the merge is classified:
     *
     * - If all differences are safe auto-merges: proceed with sync normally.
     * - If any differences require review: stage the conflicts in metadata and
     *   skip the sync for the conflicting items, preserving the current canonical list.
     *
     * When merge planning is not required (no high-confidence livestream items, or
     * incoming source is also livestream), this delegates directly to sync + finalize.
     *
     * ## Unmatched incoming items
     *
     * Items with no counterpart among the existing livestream items are classified as
     * `unmatched_incoming` and never counted as conflicts — an entirely new item cannot
     * contradict a detection. They are not applied separately, though: staging holds
     * the whole incoming list, additions included, so the reviewer resolves one
     * coherent proposal rather than a list already half-applied.
     *
     * @param  array<int, array<string, mixed>>  $incomingItems
     */
    public function merge(
        ChurchService $churchService,
        array $incomingItems,
        ChurchServiceItemSource $incomingSource,
    ): StructureMergeResult {
        // Classification decides whether these items reach the sync at all, so it has
        // to see the same catalogue links the sync would resolve. Without this a
        // hand-typed "Amazng Grace" is staged as a conflict against the very song it
        // resolves to, and the resolution never runs.
        $incomingItems = $this->catalogueSongResolver->resolveAll($incomingItems, $incomingSource);

        if (! $this->policy->requiresMergePlanning($churchService, $incomingSource)) {
            return $this->directMerge($churchService, $incomingItems, $incomingSource);
        }

        $existingSnapshots = $this->canonicalStateService->snapshot($churchService);
        $classification = $this->policy->classifyIncomingItems(
            $existingSnapshots,
            $incomingItems,
            $incomingSource,
        );

        if ($classification['review_required'] === []) {
            return $this->directMerge($churchService, $incomingItems, $incomingSource);
        }

        return $this->stageForReview(
            $churchService,
            $incomingItems,
            $incomingSource,
            $existingSnapshots,
            $classification,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $incomingItems
     */
    private function directMerge(
        ChurchService $churchService,
        array $incomingItems,
        ChurchServiceItemSource $incomingSource,
    ): StructureMergeResult {
        $beforeSnapshot = $this->canonicalStateService->snapshot($churchService);

        try {
            $syncResult = $this->itemSyncService->sync($churchService, $incomingItems, $incomingSource);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'church_service_items_active_position_unique')) {
                throw new RuntimeException('Service item ordering conflict: the merge could not complete because two items share the same position.');
            }

            throw $exception;
        }

        $churchService = $this->canonicalUpdateService->finalize(
            $churchService,
            $beforeSnapshot,
            $incomingSource,
            $syncResult,
        );

        Log::info('Structure merge: auto-merged', [
            'church_service_id' => $churchService->id,
            'incoming_source' => $incomingSource->value,
            'items_synced' => count($incomingItems),
        ]);

        return new StructureMergeResult(
            churchService: $churchService,
            incomingSource: $incomingSource,
            wasMerged: true,
            wasStaged: false,
            syncResult: $syncResult,
            reason: 'Auto-merged: no high-confidence conflicts detected',
        );
    }

    /**
     * Stage conflicting incoming items for review without overwriting the canonical list.
     *
     * @param  array<int, array<string, mixed>>  $incomingItems
     * @param  array<int, array<string, mixed>>  $existingSnapshots
     * @param  array{auto_merge: list<int>, review_required: list<int>, unmatched_incoming: list<int>}  $classification
     */
    private function stageForReview(
        ChurchService $churchService,
        array $incomingItems,
        ChurchServiceItemSource $incomingSource,
        array $existingSnapshots,
        array $classification,
    ): StructureMergeResult {
        $stagedConflicts = $this->buildStagedConflicts(
            $incomingItems,
            $existingSnapshots,
            $classification,
        );

        $pendingMerge = [
            'created_at' => now()->toIso8601String(),
            'confidence' => [
                'current' => $this->summariseConfidence($existingSnapshots),
                'incoming' => 'high',
            ],
            'conflicts' => $stagedConflicts,
            'proposed_items' => array_values($incomingItems),
            'classification' => $classification,
        ];

        $importMetadata = $churchService->import_metadata?->toArray() ?? [];
        $importMetadata['pending_structure_merge'] = $pendingMerge;

        $churchService->forceFill([
            'needs_review' => true,
            'pending_structure_merge_source' => $incomingSource->value,
            'import_metadata' => $importMetadata,
        ])->save();

        Log::info('Structure merge: staged for review', [
            'church_service_id' => $churchService->id,
            'incoming_source' => $incomingSource->value,
            'conflict_count' => count($stagedConflicts),
            'proposed_item_count' => count($incomingItems),
            'classification' => $classification,
        ]);

        return new StructureMergeResult(
            churchService: $churchService->fresh() ?? $churchService,
            incomingSource: $incomingSource,
            wasMerged: false,
            wasStaged: true,
            stagedConflicts: $stagedConflicts,
            reason: 'Staged for review: high-confidence livestream items conflict with incoming '.$incomingSource->value.' data',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $incomingItems
     * @param  array<int, array<string, mixed>>  $existingSnapshots
     * @param  array{auto_merge: list<int>, review_required: list<int>, unmatched_incoming: list<int>}  $classification
     * @return list<array<string, mixed>>
     */
    private function buildStagedConflicts(
        array $incomingItems,
        array $existingSnapshots,
        array $classification,
    ): array {
        $conflicts = [];
        $existingByPosition = collect($existingSnapshots)->keyBy('position');

        foreach ($classification['review_required'] as $index) {
            $incoming = $incomingItems[$index] ?? null;
            if (! is_array($incoming)) {
                continue;
            }

            $position = $incoming['position'] ?? ($index + 1);
            $currentItem = $existingByPosition->get($position);
            $conflictType = $this->classifyConflictType($currentItem, $incoming);

            $conflict = [
                'type' => $conflictType,
                'incoming_item' => $incoming,
            ];

            if (is_array($currentItem)) {
                $conflict['current_item'] = $currentItem;
            }

            $conflicts[] = $conflict;
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $incoming
     */
    private function classifyConflictType(?array $current, array $incoming): string
    {
        if ($current === null) {
            return 'new_item_conflict';
        }

        $currentSectionType = $current['section_type'] ?? null;
        $incomingSectionType = $incoming['section_type'] ?? null;

        if ($currentSectionType !== null && $incomingSectionType !== null && $currentSectionType !== $incomingSectionType) {
            return 'type_conflict';
        }

        $currentTitle = $current['title'] ?? '';
        $incomingTitle = $incoming['title'] ?? '';

        if ($currentTitle !== $incomingTitle) {
            return 'title_conflict';
        }

        $currentPosition = $current['position'] ?? null;
        $incomingPosition = $incoming['position'] ?? null;

        if ($currentPosition !== $incomingPosition) {
            return 'position_conflict';
        }

        return 'general_conflict';
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     */
    private function summariseConfidence(array $snapshots): string
    {
        $hasHigh = false;
        $hasLow = false;

        foreach ($snapshots as $snapshot) {
            $confidence = is_numeric($snapshot['confidence'] ?? null)
                ? ServiceSectionConfidence::levelFor((float) $snapshot['confidence'])
                : 'unknown';

            if ($confidence === 'high') {
                $hasHigh = true;
            } elseif ($confidence !== 'unknown') {
                $hasLow = true;
            }
        }

        if ($hasHigh && $hasLow) {
            return 'mixed';
        }

        return $hasHigh ? 'high' : 'low';
    }
}
