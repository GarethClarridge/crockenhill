<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CalendarEvent;
use App\Models\ChurchService;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Observers\CalendarEventObserver;
use App\Observers\ChurchServiceObserver;
use App\Observers\MediaLibraryCacheObserver;
use App\Observers\PreacherAliasObserver;
use App\Observers\PreacherObserver;
use App\Observers\PublicReadModelCacheObserver;
use App\Observers\SermonIdentityObserver;
use App\Observers\SermonObserver;
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
        Sermon::observe(SermonIdentityObserver::class);
        Sermon::observe(SermonObserver::class);

        // PublicReadModelCacheObserver handles events after commit, by which
        // time the meetings.page_id ON DELETE SET NULL FK has already severed
        // the relationship — load it while the row still links so deleted()
        // can forget the surviving meeting's read model. A forced load, not
        // loadMissing: the instance may carry a stale null relation cached
        // before the meeting was linked.
        Page::deleting(function (Page $page): void {
            $page->load('meeting');
        });

        Page::observe(PublicReadModelCacheObserver::class);
        Meeting::observe(PublicReadModelCacheObserver::class);
        Preacher::observe(PreacherObserver::class);
        PreacherAlias::observe(PreacherAliasObserver::class);
    }
}
