<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;

class ChurchServiceCanonicalStateService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(?ChurchService $churchService): array
    {
        if (! $churchService instanceof ChurchService || ! $churchService->exists) {
            return [];
        }

        $items = $churchService->relationLoaded('items')
            ? $churchService->items
            : $churchService->items()->orderBy('position')->orderBy('id')->get();

        return $items
            ->map(fn (ChurchServiceItem $item): array => [
                'id' => $item->id,
                'position' => $item->position,
                'type' => $item->type,
                'source' => $item->source?->value,
                'title' => $item->title,
                'source_title' => $item->source_title,
                'openlp_search_title' => $item->openlp_search_title,
                'song_id' => $item->song_id,
                'metadata' => $this->normaliseValue($item->metadata),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array<int, array<string, mixed>>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        $beforeById = collect($before)->keyBy('id');
        $afterById = collect($after)->keyBy('id');

        foreach ($after as $afterItem) {
            $itemId = $afterItem['id'] ?? null;
            if (! is_int($itemId)) {
                continue;
            }

            /** @var array<string, mixed>|null $beforeItem */
            $beforeItem = $beforeById->get($itemId);

            if (! is_array($beforeItem)) {
                $changes[] = [
                    'type' => 'added_item',
                    'item' => $afterItem,
                ];

                continue;
            }

            $fieldChanges = [];

            foreach (['position', 'type', 'source', 'title', 'source_title', 'openlp_search_title', 'song_id', 'metadata'] as $field) {
                if (($beforeItem[$field] ?? null) === ($afterItem[$field] ?? null)) {
                    continue;
                }

                $fieldChanges[] = [
                    'field' => $field,
                    'before' => $beforeItem[$field] ?? null,
                    'after' => $afterItem[$field] ?? null,
                ];
            }

            if ($fieldChanges === []) {
                continue;
            }

            $changes[] = [
                'type' => 'updated_item',
                'item_id' => $itemId,
                'before' => $beforeItem,
                'after' => $afterItem,
                'fields' => $fieldChanges,
            ];
        }

        foreach ($before as $beforeItem) {
            $itemId = $beforeItem['id'] ?? null;
            if (! is_int($itemId) || $afterById->has($itemId)) {
                continue;
            }

            $changes[] = [
                'type' => 'removed_item',
                'item' => $beforeItem,
            ];
        }

        return $changes;
    }

    private function normaliseValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->normaliseValue($nested);
        }

        if (array_is_list($value)) {
            return $value;
        }

        ksort($value);

        return $value;
    }
}
