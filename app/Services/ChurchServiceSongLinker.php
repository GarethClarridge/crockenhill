<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ChurchServiceSongLinker
{
    /**
     * @return array{
     *     dry_run:bool,
     *     processed:int,
     *     matched:int,
     *     unmatched:int,
     *     updated:int,
     *     unchanged:int,
     *     cleared:int
     * }
     */
    public function linkAll(bool $dryRun = false): array
    {
        $query = ChurchServiceItem::query()
            ->where('type', 'songs')
            ->whereNull('deleted_at');

        return $this->linkQuery($query, $dryRun);
    }

    /**
     * @return array{
     *     dry_run:bool,
     *     processed:int,
     *     matched:int,
     *     unmatched:int,
     *     updated:int,
     *     unchanged:int,
     *     cleared:int
     * }
     */
    public function linkForService(ChurchService $churchService, bool $dryRun = false): array
    {
        $query = ChurchServiceItem::query()
            ->where('church_service_id', $churchService->id)
            ->where('type', 'songs')
            ->whereNull('deleted_at');

        return $this->linkQuery($query, $dryRun);
    }

    /**
     * @param  Builder<ChurchServiceItem>  $query
     * @return array{
     *     dry_run:bool,
     *     processed:int,
     *     matched:int,
     *     unmatched:int,
     *     updated:int,
     *     unchanged:int,
     *     cleared:int
     * }
     */
    private function linkQuery(Builder $query, bool $dryRun): array
    {
        /** @var array<string, int> $songLookup */
        $songLookup = Song::query()
            ->pluck('id', 'canonical_key')
            ->mapWithKeys(static fn ($songId, $canonicalKey): array => [(string) $canonicalKey => (int) $songId])
            ->all();

        $metrics = [
            'dry_run' => $dryRun,
            'processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'cleared' => 0,
        ];

        $query->orderBy('id')->chunkById(250, function (Collection $items) use (&$metrics, $songLookup, $dryRun): void {
            foreach ($items as $item) {
                $metrics['processed']++;

                $searchTitle = $this->resolveSearchTitle($item);
                if ($searchTitle === null) {
                    $metrics['unmatched']++;
                    $this->clearLinkIfNeeded($item, $dryRun, $metrics);

                    continue;
                }

                $canonicalKey = Song::canonicalizeKey($searchTitle);
                if ($canonicalKey === '') {
                    $metrics['unmatched']++;
                    $this->clearLinkIfNeeded($item, $dryRun, $metrics);

                    continue;
                }

                $songId = $songLookup[$canonicalKey] ?? null;
                if ($songId === null) {
                    $metrics['unmatched']++;
                    $this->clearLinkIfNeeded($item, $dryRun, $metrics);

                    continue;
                }

                $metrics['matched']++;

                if ($item->song_id === $songId) {
                    $metrics['unchanged']++;

                    continue;
                }

                if (! $dryRun) {
                    $item->song_id = $songId;
                    $item->save();
                }

                $metrics['updated']++;
            }
        });

        return $metrics;
    }

    private function resolveSearchTitle(ChurchServiceItem $item): ?string
    {
        foreach ([$item->openlp_search_title, $item->source_title, $item->title] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param  array{dry_run:bool,processed:int,matched:int,unmatched:int,updated:int,unchanged:int,cleared:int}  $metrics
     */
    private function clearLinkIfNeeded(ChurchServiceItem $item, bool $dryRun, array &$metrics): void
    {
        if ($item->song_id === null) {
            $metrics['unchanged']++;

            return;
        }

        if (! $dryRun) {
            $item->song_id = null;
            $item->save();
        }

        $metrics['cleared']++;
    }
}
