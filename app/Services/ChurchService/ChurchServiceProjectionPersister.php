<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceProjection;
use App\Models\ChurchService;
use Illuminate\Support\Facades\DB;

class ChurchServiceProjectionPersister
{
    public function apply(ChurchService $churchService, ChurchServiceProjection $projection): ChurchService
    {
        return DB::transaction(function () use ($churchService, $projection): ChurchService {
            $lockedService = ChurchService::query()
                ->whereKey($churchService->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedService->canonical_hash === $projection->hash) {
                return $lockedService;
            }

            $existingItems = $lockedService->items()->get()->keyBy('canonical_identity');
            $unassignedItems = $lockedService->items()->get()->keyBy('id');
            $retainedIds = [];

            foreach ($projection->items as $item) {
                $identity = (string) $item['canonical_identity'];
                $existing = $existingItems->get($identity);
                $existing ??= $unassignedItems->first(function ($candidate) use ($item): bool {
                    if ($item['song_id'] !== null && $candidate->song_id === $item['song_id']) {
                        return true;
                    }

                    $candidateTitles = array_filter([$candidate->title, $candidate->source_title]);
                    $projectedTitles = array_filter([$item['title'], $item['source_title']]);

                    return $candidate->type === $item['type']
                        && array_intersect($candidateTitles, $projectedTitles) !== [];
                });
                $values = [
                    ...$item,
                    'position' => 100000 + (int) $item['position'],
                ];

                if ($existing === null) {
                    $existing = $lockedService->items()->create($values);
                } else {
                    $existing->forceFill($values)->saveQuietly();
                }

                $retainedIds[] = $existing->getKey();
                $unassignedItems->forget($existing->getKey());
            }

            $lockedService->items()
                ->whereNotIn('id', $retainedIds)
                ->delete();

            foreach ($projection->items as $item) {
                $lockedService->items()
                    ->where('canonical_identity', $item['canonical_identity'])
                    ->update(['position' => $item['position']]);
            }

            $lockedService->forceFill([
                'summary' => $projection->serviceContent['summary'],
                'notices' => $projection->serviceContent['notices'],
                'chapter_markers' => $projection->serviceContent['chapter_markers'],
                'source_summary' => $projection->sourceSummary,
                'source' => $projection->sourceSummary,
                'canonical_revision' => $lockedService->canonical_revision + 1,
                'canonical_hash' => $projection->hash,
            ])->saveQuietly();

            return $lockedService->fresh(['items']) ?? $lockedService;
        });
    }
}
