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
     * Clear the cache for a specific area.
     */
    public function clearAreaCache(string|PageArea $area): void
    {
        $areaValue = $area instanceof PageArea ? $area->value : $area;
        Cache::forget("page_links_{$areaValue}");
    }
}
