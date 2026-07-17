<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PageListCache
{
    public static function areaCacheKey(string|PageArea $area): string
    {
        return 'page_links_'.self::areaValue($area);
    }

    /**
     * Forget the cached link list for an area. Used on page exposure
     * transitions, where TTL freshness is not enough (see
     * PublicReadModelCacheObserver).
     */
    public static function forgetAreaCache(string|PageArea $area): void
    {
        Cache::forget(self::areaCacheKey($area));
    }

    private static function areaValue(string|PageArea $area): string
    {
        return $area instanceof PageArea ? $area->value : $area;
    }

    /**
     * Get and cache all public links for an area.
     *
     * @return Collection<int, Page>
     */
    public function getAllLinksForArea(string|PageArea $area): Collection
    {
        $areaValue = self::areaValue($area);
        $key = self::areaCacheKey($area);

        return Cache::flexible($key, [300, 86400], function () use ($areaValue): Collection {
            return Page::query()
                ->public()
                ->select(['id', 'slug', 'heading', 'area', 'description', 'admin', 'navigation'])
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
        $areaValue = self::areaValue($area);
        $orderedSlugs = array_values(array_unique($slugs));

        if ($orderedSlugs === []) {
            return collect();
        }

        return Cache::flexible($cacheKey, [300, 86400], function () use ($areaValue, $orderedSlugs): Collection {
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
    }

    /**
     * Get and cache public links by their slugs across all non-member areas.
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

        return Cache::flexible($cacheKey, [300, 86400], function () use ($orderedSlugs): Collection {
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
    }
}
