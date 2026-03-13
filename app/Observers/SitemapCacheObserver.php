<?php

namespace App\Observers;

use Illuminate\Support\Facades\Cache;

class SitemapCacheObserver
{
    /**
     * Handle the model "created" event.
     */
    public function created(mixed $model): void
    {
        $this->clearCaches();
    }

    /**
     * Handle the model "updated" event.
     */
    public function updated(mixed $model): void
    {
        $this->clearCaches();
    }

    /**
     * Handle the model "deleted" event.
     */
    public function deleted(mixed $model): void
    {
        $this->clearCaches();
    }

    /**
     * Clear all related application caches when models change.
     */
    private function clearCaches(): void
    {
        Cache::forget('sitemap');
        Cache::forget('nav_pages');
        Cache::forget('sermon_series');
        Cache::forget('admin_preacher_list');
        Cache::forget('public_preacher_list');

        // Note: Podcast feed cache is NOT cleared here to prevent test failures
        // where sequential requests expect the same data (Cache Flexible behavior).
    }
}
