<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChurchServiceItemSyncService
{
    /**
     * @param  array<int, array<string, mixed>>  $incomingItems
     */
    public function sync(ChurchService $churchService, array $incomingItems): void
    {
        DB::transaction(function () use ($churchService, $incomingItems): void {
            $lockedService = ChurchService::query()
                ->whereKey($churchService->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Collection<int, ChurchServiceItem> $existingItems */
            $existingItems = $lockedService->items()->withTrashed()->orderBy('id')->get();
            $matchedExistingItemIds = [];
            $seenPositions = [];

            foreach ($incomingItems as $index => $rawIncomingItem) {
                $incomingItem = $this->normaliseIncomingItem($rawIncomingItem, $index + 1);

                if (in_array($incomingItem['position'], $seenPositions, true)) {
                    throw new RuntimeException('Incoming items contain duplicate active positions.');
                }

                $seenPositions[] = $incomingItem['position'];

                $stableMatch = $this->findStableMatch(
                    $existingItems,
                    $incomingItem,
                    $matchedExistingItemIds
                );

                $positionFallback = $this->findPositionFallbackMatch(
                    $existingItems,
                    $incomingItem,
                    $matchedExistingItemIds
                );

                $match = $stableMatch ?? $positionFallback;

                if ($match !== null) {
                    $this->updateMatchedItem($match, $incomingItem);
                    $matchedExistingItemIds[] = $match->id;

                    continue;
                }

                $created = $lockedService->items()->create([
                    'position' => $incomingItem['position'],
                    'type' => $incomingItem['type'],
                    'title' => $incomingItem['title'],
                    'source_title' => $incomingItem['source_title'],
                    'openlp_search_title' => $incomingItem['openlp_search_title'],
                    'metadata' => $incomingItem['metadata'],
                ]);

                $matchedExistingItemIds[] = $created->id;
            }

            foreach ($existingItems as $existingItem) {
                if (
                    ! in_array($existingItem->id, $matchedExistingItemIds, true)
                    && ! $existingItem->trashed()
                ) {
                    $existingItem->delete();
                }
            }

            $this->assertUniqueActivePositions($lockedService);
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}
     */
    private function normaliseIncomingItem(array $item, int $defaultPosition): array
    {
        $position = $item['position'] ?? $defaultPosition;
        $type = $this->normaliseString($item['type'] ?? null);
        $title = $this->normaliseString($item['title'] ?? null);

        if (! is_int($position) || $position < 1) {
            throw new RuntimeException('Incoming service items must include a positive integer position.');
        }

        if ($type === null) {
            throw new RuntimeException('Incoming service items must include a type.');
        }

        if ($title === null) {
            throw new RuntimeException('Incoming service items must include a title.');
        }

        $metadata = $item['metadata'] ?? null;

        if (! is_array($metadata) && $metadata !== null) {
            throw new RuntimeException('Incoming service item metadata must be an array or null.');
        }

        return [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'source_title' => $this->normaliseString($item['source_title'] ?? null),
            'openlp_search_title' => $this->normaliseString($item['openlp_search_title'] ?? null),
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  Collection<int, ChurchServiceItem>  $existingItems
     * @param  array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}  $incomingItem
     * @param  array<int, int>  $matchedExistingItemIds
     */
    private function findStableMatch(Collection $existingItems, array $incomingItem, array $matchedExistingItemIds): ?ChurchServiceItem
    {
        /** @var ChurchServiceItem|null $match */
        $match = $existingItems->first(function (ChurchServiceItem $existingItem) use ($incomingItem, $matchedExistingItemIds): bool {
            if (in_array($existingItem->id, $matchedExistingItemIds, true)) {
                return false;
            }

            if ($existingItem->type !== $incomingItem['type']) {
                return false;
            }

            $incomingSearchTitle = $incomingItem['openlp_search_title'];
            if ($incomingSearchTitle !== null && $existingItem->openlp_search_title === $incomingSearchTitle) {
                return true;
            }

            $incomingSourceTitle = $incomingItem['source_title'];
            if ($incomingSourceTitle !== null && $existingItem->source_title === $incomingSourceTitle) {
                return true;
            }

            return false;
        });

        return $match;
    }

    /**
     * @param  Collection<int, ChurchServiceItem>  $existingItems
     * @param  array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}  $incomingItem
     * @param  array<int, int>  $matchedExistingItemIds
     */
    private function findPositionFallbackMatch(Collection $existingItems, array $incomingItem, array $matchedExistingItemIds): ?ChurchServiceItem
    {
        /** @var ChurchServiceItem|null $match */
        $match = $existingItems->first(function (ChurchServiceItem $existingItem) use ($incomingItem, $matchedExistingItemIds): bool {
            if (in_array($existingItem->id, $matchedExistingItemIds, true)) {
                return false;
            }

            return $existingItem->type === $incomingItem['type']
                && $existingItem->position === $incomingItem['position'];
        });

        return $match;
    }

    /**
     * @param  array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}  $incomingItem
     */
    private function updateMatchedItem(ChurchServiceItem $existingItem, array $incomingItem): void
    {
        if ($existingItem->trashed()) {
            $existingItem->restore();
        }

        $existingItem->fill([
            'position' => $incomingItem['position'],
            'type' => $incomingItem['type'],
            'title' => $incomingItem['title'],
            'source_title' => $incomingItem['source_title'],
            'openlp_search_title' => $incomingItem['openlp_search_title'],
            'metadata' => $incomingItem['metadata'],
        ]);

        $existingItem->save();
    }

    private function assertUniqueActivePositions(ChurchService $churchService): void
    {
        $hasDuplicatePositions = $churchService
            ->items()
            ->select('position')
            ->groupBy('position')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicatePositions) {
            throw new RuntimeException('Active service items must have unique positions per service.');
        }
    }

    private function normaliseString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalised = trim((string) preg_replace('/\s+/', ' ', $value));

        return $normalised === '' ? null : $normalised;
    }
}
