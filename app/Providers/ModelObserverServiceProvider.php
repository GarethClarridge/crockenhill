<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ChurchService;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Observers\ChurchServiceObserver;
use App\Observers\SermonObserver;
use App\Observers\SitemapCacheObserver;
use Illuminate\Support\ServiceProvider;

class ModelObserverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        ChurchService::observe(ChurchServiceObserver::class);
        Sermon::observe(SermonObserver::class);
        Sermon::observe(SitemapCacheObserver::class);
        Page::observe(SitemapCacheObserver::class);
        Meeting::observe(SitemapCacheObserver::class);
        Preacher::observe(SitemapCacheObserver::class);
    }
}
