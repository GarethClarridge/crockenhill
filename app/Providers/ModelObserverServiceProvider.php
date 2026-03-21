<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CalendarEvent;
use App\Models\ChurchService;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Observers\CalendarEventObserver;
use App\Observers\ChurchServiceObserver;
use App\Observers\MediaLibraryCacheObserver;
use App\Observers\SermonObserver;
use App\Observers\SitemapCacheObserver;
use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ModelObserverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        CalendarEvent::observe(CalendarEventObserver::class);
        ChurchService::observe(ChurchServiceObserver::class);
        Media::observe(MediaLibraryCacheObserver::class);
        Sermon::observe(SermonObserver::class);
        Sermon::observe(SitemapCacheObserver::class);
        Page::observe(SitemapCacheObserver::class);
        Meeting::observe(SitemapCacheObserver::class);
        Preacher::observe(SitemapCacheObserver::class);
    }
}
