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
        Cache::forget('sitemap');
        Cache::forget('nav_pages');
    }

    /**
     * Handle the model "updated" event.
     */
    public function updated(mixed $model): void
    {
        Cache::forget('sitemap');
        Cache::forget('nav_pages');
    }

    /**
     * Handle the model "deleted" event.
     */
    public function deleted(mixed $model): void
    {
        Cache::forget('sitemap');
        Cache::forget('nav_pages');
    }
}
