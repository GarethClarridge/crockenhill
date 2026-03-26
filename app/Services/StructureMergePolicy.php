<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;

class StructureMergePolicy
{
    /**
     * Determine whether an incoming source import should go through merge planning
     * rather than direct sync when a matching service already exists.
     *
     * Merge planning only applies when the existing service was projected from
     * livestream and the incoming source is a different, higher-authority source.
     */
    public function requiresMergePlanning(
        ChurchService $churchService,
        ChurchServiceItemSource $incomingSource,
    ): bool {
        if ($incomingSource === ChurchServiceItemSource::LIVESTREAM) {
            return false;
        }

        return $this->hasHighConfidenceLivestreamItems($churchService);
    }

    /**
     * Classify whether a specific item-level difference is safe to auto-merge
     * or requires human review.
     *
     * @param  array<string, mixed>  $existingSnapshot
     * @param  array<string, mixed>  $incomingItem
     */
    public function isAutoMergeSafe(
        array $existingSnapshot,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource,
    ): bool {
        $existingConfidence = $this->itemConfidenceLevel($existingSnapshot);

        if ($existingConfidence !== 'high') {
            return true;
        }

        if ($this->isEnrichmentOnly($existingSnapshot, $incomingItem)) {
            return true;
        }

        if ($this->isSongIdentificationMatch($existingSnapshot, $incomingItem)) {
            return true;
        }

        return false;
    }

    /**
     * Compare two full item lists and return the indices of items that require review.
     *
     * @param  array<int, array<string, mixed>>  $existingSnapshots
     * @param  array<int, array<string, mixed>>  $incomingItems
     * @return array{auto_merge: list<int>, review_required: list<int>, unmatched_incoming: list<int>}
     */
    public function classifyIncomingItems(
        array $existingSnapshots,
        array $incomingItems,
        ChurchServiceItemSource $incomingSource,
    ): array {
        $autoMerge = [];
        $reviewRequired = [];
        $unmatchedIncoming = [];

        $existingByPosition = [];
        foreach ($existingSnapshots as $snapshot) {
            $position = $snapshot['position'] ?? null;
            if (is_int($position)) {
                $existingByPosition[$position] = $snapshot;
            }
        }

        foreach ($incomingItems as $index => $incomingItem) {
            $position = $incomingItem['position'] ?? ($index + 1);
            $matchingExisting = $existingByPosition[$position] ?? null;

            if ($matchingExisting === null) {
                $matchingExisting = $this->findTypeMatch($existingSnapshots, $incomingItem, $incomingSource);
            }

            if ($matchingExisting === null) {
                $unmatchedIncoming[] = $index;

                continue;
            }

            if ($this->isAutoMergeSafe($matchingExisting, $incomingItem, $incomingSource)) {
                $autoMerge[] = $index;
            } else {
                $reviewRequired[] = $index;
            }
        }

        return [
            'auto_merge' => $autoMerge,
            'review_required' => $reviewRequired,
            'unmatched_incoming' => $unmatchedIncoming,
        ];
    }

    private function hasHighConfidenceLivestreamItems(ChurchService $churchService): bool
    {
        return $churchService->items()
            ->where('source', ChurchServiceItemSource::LIVESTREAM->value)
            ->get()
            ->contains(fn (ChurchServiceItem $item): bool => $this->itemConfidenceLevel($this->itemToSnapshot($item)) === 'high');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function itemConfidenceLevel(array $snapshot): string
    {
        $metadata = is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [];
        $projection = is_array($metadata['livestream_projection'] ?? null) ? $metadata['livestream_projection'] : [];

        return is_string($projection['confidence_level'] ?? null) ? $projection['confidence_level'] : 'unknown';
    }

    /**
     * An incoming item is enrichment-only when it fills blanks or improves metadata
     * without changing the semantic meaning of the existing item.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function isEnrichmentOnly(array $existing, array $incoming): bool
    {
        $existingTitle = $existing['title'] ?? '';
        $incomingTitle = $incoming['title'] ?? '';
        $existingSongId = $existing['song_id'] ?? null;
        $incomingSongId = $incoming['song_id'] ?? null;

        if ($existingTitle === '' && $incomingTitle !== '') {
            return true;
        }

        if ($existingSongId === null && $incomingSongId !== null) {
            $existingType = $existing['type'] ?? '';
            $incomingType = $incoming['type'] ?? '';
            if ($existingType === $incomingType) {
                return true;
            }
        }

        $existingSourceTitle = $existing['source_title'] ?? null;
        $incomingSourceTitle = $incoming['source_title'] ?? null;
        if ($existingSourceTitle === null && $incomingSourceTitle !== null) {
            return $this->titlesSemanticallyMatch($existingTitle, $incomingTitle);
        }

        return false;
    }

    /**
     * When both sides identify the same song, the merge is safe regardless of
     * minor metadata differences.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function isSongIdentificationMatch(array $existing, array $incoming): bool
    {
        $existingType = $existing['type'] ?? '';
        $incomingType = $incoming['type'] ?? '';

        if ($existingType !== 'songs' || $incomingType !== 'songs') {
            return false;
        }

        $existingSongId = $existing['song_id'] ?? null;
        $incomingSongId = $incoming['song_id'] ?? null;

        if (is_int($existingSongId) && $existingSongId === $incomingSongId) {
            return true;
        }

        return $this->titlesSemanticallyMatch(
            (string) ($existing['title'] ?? ''),
            (string) ($incoming['title'] ?? ''),
        );
    }

    private function titlesSemanticallyMatch(string $existingTitle, string $incomingTitle): bool
    {
        if ($existingTitle === '' || $incomingTitle === '') {
            return false;
        }

        $normalised = fn (string $value): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));

        return $normalised($existingTitle) === $normalised($incomingTitle);
    }

    /**
     * @param  array<int, array<string, mixed>>  $existingSnapshots
     * @param  array<string, mixed>  $incomingItem
     * @return array<string, mixed>|null
     */
    private function findTypeMatch(
        array $existingSnapshots,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource,
    ): ?array {
        $incomingType = $incomingItem['type'] ?? null;
        $incomingSectionType = $incomingItem['section_type'] ?? null;

        foreach ($existingSnapshots as $snapshot) {
            if (($snapshot['type'] ?? null) === $incomingType && ($snapshot['section_type'] ?? null) === $incomingSectionType) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemToSnapshot(ChurchServiceItem $item): array
    {
        return [
            'id' => $item->id,
            'position' => $item->position,
            'type' => $item->type,
            'section_type' => $item->semanticSectionType()->value,
            'source' => $item->source?->value,
            'title' => $item->title,
            'source_title' => $item->source_title,
            'openlp_search_title' => $item->openlp_search_title,
            'song_id' => $item->song_id,
            'metadata' => $item->metadata,
        ];
    }
}
