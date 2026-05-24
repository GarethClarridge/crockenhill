<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PageRepository
{
    /**
     * @var list<string>
     */
    private const PAGE_CARD_CACHE_KEYS = [
        'page_card_rail_home',
        'page_card_rail_community',
        'page_card_rail_church',
    ];

    /**
     * @var array<string, Collection<int, Page>>
     */
    private array $memoizedPresents = [];

    /**
     * Get and cache all public links for an area.
     *
     * Performance Optimization: Implements request-level memoization to avoid
     * redundant flexible cache lookups when referenced multiple times.
     *
     * @return Collection<int, Page>
     */
    public function getAllLinksForArea(string|PageArea $area): Collection
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;
        $cacheKey = "page_links_{$areaValue}";

        if (isset($this->memoizedPresents[$cacheKey])) {
            return $this->memoizedPresents[$cacheKey];
        }

        return $this->memoizedPresents[$cacheKey] = Cache::flexible($cacheKey, [86400, 172800], function () use ($areaValue) {
            return Page::query()
                ->public()
                ->select(['id', 'slug', 'heading', 'area', 'description', 'admin'])
                ->with('media')
                ->where('area', $areaValue)
                ->get();
        });
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, Page>
     */
    public function getLinksForAreaSlugs(string|PageArea $area, array $slugs, string $cacheKey): Collection
    {
        if (isset($this->memoizedPresents[$cacheKey])) {
            return $this->memoizedPresents[$cacheKey];
        }

        $areaValue = $area instanceof PageArea ? $area->value : $area;
        $orderedSlugs = array_values(array_unique($slugs));

        if ($orderedSlugs === []) {
            return collect();
        }

        return $this->memoizedPresents[$cacheKey] = Cache::flexible($cacheKey, [86400, 172800], function () use ($areaValue, $orderedSlugs) {
            return Page::query()
                ->public()
                ->select(['id', 'slug', 'heading', 'area', 'description', 'admin'])
                ->with('media')
                ->where('area', $areaValue)
                ->whereIn('slug', $orderedSlugs)
                ->get()
                ->sortBy(function (Page $page) use ($orderedSlugs): int {
                    $position = array_search($page->slug, $orderedSlugs, true);

                    return $position === false ? PHP_INT_MAX : $position;
                })
                ->values();
        });
    }

    /**
     * Clear the internal request-level memoization.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedPresents = [];
    }

    /**
     * Clear the cache for a specific area.
     */
    public function clearAreaCache(string|PageArea $area): void
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;
        $this->forgetFlexible("page_links_{$areaValue}");

        foreach (self::PAGE_CARD_CACHE_KEYS as $cacheKey) {
            $this->forgetFlexible($cacheKey);
        }
    }

    private function forgetFlexible(string $cacheKey): void
    {
        Cache::forget($cacheKey);
        Cache::forget("illuminate:cache:flexible:created:{$cacheKey}");
    }
}
