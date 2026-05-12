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
     * Get and cache all public links for an area.
     *
     * @return Collection<int, Page>
     */
    public function getAllLinksForArea(string|PageArea $area): Collection
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;

        return Cache::flexible("page_links_{$areaValue}", [86400, 172800], function () use ($areaValue) {
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
        $areaValue = $area instanceof PageArea ? $area->value : $area;
        $orderedSlugs = array_values(array_unique($slugs));

        if ($orderedSlugs === []) {
            return collect();
        }

        return Cache::flexible($cacheKey, [86400, 172800], function () use ($areaValue, $orderedSlugs) {
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
