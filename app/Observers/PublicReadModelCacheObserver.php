<?php

declare(strict_types=1);

namespace App\Observers;

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

        // A fresh load: the instance may carry a stale null relation cached
        // before the meeting was linked. After deletion the row is gone and
        // the meetings.page_id FK already set null, so rely on the relation
        // preloaded by Page::booted()'s deleting hook instead.
        if ($model->exists) {
            $model->load('meeting');
        } else {
            $model->loadMissing('meeting');
        }

        if ($model->meeting !== null) {
            $this->publicMeetingReadModelCache->forget($model->meeting);
        }
    }

    /**
     * Evict the cached navigation and card collections a page can appear in.
     * A newly restricted page would otherwise linger in cached navigation and
     * card rails until the stale window closes. The rail and area keys are
     * owned (and evicted) by their services; only the nav key is forgotten
     * here directly.
     */
    private function forgetPageCollections(Page $page): void
    {
        FlexibleCache::forget(Header::NAV_CACHE_KEY);
        PageCardService::forgetRailCaches();
        PageListCache::forgetAreaCache($page->area);

        // getPrevious() returns raw attribute values, so a changed area
        // arrives as its string value, never a cast enum.
        $previousArea = $page->getPrevious()['area'] ?? null;

        if (is_string($previousArea) && $previousArea !== '') {
            PageListCache::forgetAreaCache($previousArea);
        }
    }
}
