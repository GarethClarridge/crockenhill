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
     * Get and cache all public links for an area.
     *
     * @return Collection<int, Page>
     */
    public function getAllLinksForArea(string|PageArea $area): Collection
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;
        $key = "page_links_{$areaValue}";

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
        $areaValue = $area instanceof PageArea ? $area->value : $area;
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
