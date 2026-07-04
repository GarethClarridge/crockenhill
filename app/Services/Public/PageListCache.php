<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PageListCache
{
    /**
     * @var list<string>
     */
    private const PAGE_CARD_CACHE_KEYS = [
        'page_card_rail_home',
        'page_card_rail_community',
        'page_card_rail_church',
    ];

    /** @var array<string, mixed> */
    private array $memoizedPresents = [];

    /** @var array<string, true> */
    private array $computed = [];

    /**
     * Get and cache all public links for an area.
     *
     * @return Collection<int, Page>
     */
    public function getAllLinksForArea(string|PageArea $area): Collection
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;
        $key = "page_links_{$areaValue}";

        if (isset($this->computed[$key])) {
            /** @var Collection<int, Page> */
            return $this->memoizedPresents[$key];
        }

        $links = Cache::flexible($key, [86400, 172800], function () use ($areaValue) {
            return Page::query()
                ->public()
                ->select(['id', 'slug', 'heading', 'area', 'description', 'admin', 'navigation'])
                ->where('area', $areaValue)
                ->get();
        });

        $this->computed[$key] = true;

        return $this->memoizedPresents[$key] = $links;
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, Page>
     */
    public function getLinksForAreaSlugs(string|PageArea $area, array $slugs, string $cacheKey): Collection
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;
        $orderedSlugs = array_values(array_unique($slugs));

        if ($orderedSlugs === []) {
            return collect();
        }

        if (isset($this->computed[$cacheKey])) {
            /** @var Collection<int, Page> */
            return $this->memoizedPresents[$cacheKey];
        }

        $links = Cache::flexible($cacheKey, [86400, 172800], function () use ($areaValue, $orderedSlugs) {
            return Page::query()
                ->public()
                ->select(['id', 'slug', 'heading', 'area', 'description', 'admin', 'navigation'])
                ->where('area', $areaValue)
                ->whereIn('slug', $orderedSlugs)
                ->get()
                ->sortBy(function (Page $page) use ($orderedSlugs): int {
                    $position = array_search($page->slug, $orderedSlugs, true);

                    return $position === false ? PHP_INT_MAX : $position;
                })
                ->values();
        });

        $this->computed[$cacheKey] = true;

        return $this->memoizedPresents[$cacheKey] = $links;
    }

    /**
     * Get and cache public links by their slugs across all non-member areas.
     *
     * Performance Optimization: Implements request-level memoization and flexible caching.
     *
     * @param  list<string>  $slugs
     * @return Collection<int, Page>
     */
    public function getLinksBySlugs(array $slugs, string $cacheKey): Collection
    {
        $orderedSlugs = array_values(array_unique($slugs));

        if ($orderedSlugs === []) {
            return collect();
        }

        if (isset($this->computed[$cacheKey])) {
            /** @var Collection<int, Page> */
            return $this->memoizedPresents[$cacheKey];
        }

        $links = Cache::flexible($cacheKey, [86400, 172800], function () use ($orderedSlugs) {
            return Page::query()
                ->public()
                ->select(['id', 'slug', 'heading', 'area', 'description', 'admin', 'navigation'])
                ->whereNot('area', PageArea::Members)
                ->whereIn('slug', $orderedSlugs)
                ->get()
                ->sortBy(function (Page $page) use ($orderedSlugs): int {
                    $position = array_search($page->slug, $orderedSlugs, true);

                    return $position === false ? PHP_INT_MAX : $position;
                })
                ->values();
        });

        $this->computed[$cacheKey] = true;

        return $this->memoizedPresents[$cacheKey] = $links;
    }

    /**
     * Clear all internal memoization caches.
     * Useful for long-running processes or tests.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedPresents = [];
        $this->computed = [];
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

        $this->clearInternalCaches();
    }

    private function forgetFlexible(string $cacheKey): void
    {
        Cache::forget($cacheKey);
        Cache::forget("illuminate:cache:flexible:created:{$cacheKey}");
    }
}
