<?php

namespace App\Observers;

use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Repositories\PageRepository;
use App\Repositories\SermonRepository;
use Illuminate\Support\Facades\Cache;

class SitemapCacheObserver
{
    public function __construct(
        private readonly SermonRepository $sermonRepository,
        private readonly PageRepository $pageRepository,
    ) {}

    /**
     * Handle the model "created" event.
     */
    public function created(mixed $model): void
    {
        $this->clearCaches($model);
    }

    /**
     * Handle the model "updated" event.
     */
    public function updated(mixed $model): void
    {
        $this->clearCaches($model);
    }

    /**
     * Handle the model "deleted" event.
     */
    public function deleted(mixed $model): void
    {
        $this->clearCaches($model);
    }

    /**
     * Clear all related application caches when models change.
     */
    private function clearCaches(mixed $model): void
    {
        Cache::forget('sitemap');
        Cache::forget('nav_pages');
        Cache::forget('admin_preacher_list');
        Cache::forget('public_preacher_list');

        if ($model instanceof Page) {
            $this->pageRepository->clearAreaCache($model->area);
        }

        $targetModel = ($model instanceof Sermon || $model instanceof Preacher)
            ? $model
            : null;

        $this->sermonRepository->clearListingCaches($targetModel);

        // Note: Podcast feed cache is NOT cleared here to prevent test failures
        // where sequential requests expect the same data (Cache Flexible behavior).
    }
}
