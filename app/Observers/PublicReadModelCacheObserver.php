<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PageArea;
use App\Models\Meeting;
use App\Models\Page;
use App\Services\Public\PageCardService;
use App\Services\Public\PageImageCacheService;
use App\Services\Public\PageListCache;
use App\Services\Public\PublicMeetingReadModelCache;
use App\Services\Public\PublicPageReadModelCache;
use App\Support\FlexibleCache;
use App\View\Components\Layout\Header;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PublicReadModelCacheObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Attribute changes that alter whether (and where) a page may be listed
     * publicly, as opposed to ordinary content edits whose freshness the
     * navigation and card caches' TTL already covers.
     *
     * @var list<string>
     */
    private const PAGE_EXPOSURE_ATTRIBUTES = [
        'admin',
        'area',
        'navigation',
    ];

    public function __construct(
        private readonly PageImageCacheService $pageImageCacheService,
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
        private readonly PublicPageReadModelCache $publicPageReadModelCache,
    ) {}

    public function created(Page|Meeting $model): void
    {
        $this->forget($model);

        if ($model instanceof Page) {
            $this->forgetPageCollections($model);
        }
    }

    public function updated(Page|Meeting $model): void
    {
        $this->forget($model);

        if ($model instanceof Page && $model->wasChanged(self::PAGE_EXPOSURE_ATTRIBUTES)) {
            $this->forgetPageCollections($model);
        }
    }

    public function deleted(Page|Meeting $model): void
    {
        $this->forget($model);

        if ($model instanceof Page) {
            $this->forgetPageCollections($model);
        }
    }

    private function forget(Page|Meeting $model): void
    {
        if ($model instanceof Meeting) {
            $this->publicMeetingReadModelCache->forget($model);

            return;
        }

        $this->pageImageCacheService->forget($model);
        $this->publicPageReadModelCache->forget($model);
        // On deletion the meetings.page_id FK has already been set null by the
        // time this after-commit handler runs; ModelObserverServiceProvider
        // preloads the relation in a `deleting` hook so it is still available.
        $model->loadMissing('meeting');

        if ($model->meeting !== null) {
            $this->publicMeetingReadModelCache->forget($model->meeting);
        }
    }

    /**
     * Evict the cached navigation and card collections a page can appear in.
     * A newly restricted page would otherwise linger in cached navigation and
     * card rails until the stale window closes.
     */
    private function forgetPageCollections(Page $page): void
    {
        $keys = [
            Header::NAV_CACHE_KEY,
            PageCardService::HOME_RAIL_CACHE_KEY,
            PageCardService::COMMUNITY_RAIL_CACHE_KEY,
            PageCardService::CHURCH_RAIL_CACHE_KEY,
            PageListCache::areaCacheKey($page->area),
        ];

        $previousArea = $page->getPrevious()['area'] ?? null;

        if (is_string($previousArea) || $previousArea instanceof PageArea) {
            $keys[] = PageListCache::areaCacheKey($previousArea);
        }

        foreach (array_unique($keys) as $key) {
            FlexibleCache::forget($key);
        }
    }
}
