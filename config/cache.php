<?php

use App\Data\PodcastFeedItemReadModel;
use App\Data\PublicMeetingReadModel;
use App\Data\PublicPageReadModel;
use App\Enums\CalendarEventStatus;
use App\Enums\PageArea;
use App\Models\CalendarEvent;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache connection that gets used while
    | using this caching library. This connection is used when another is
    | not explicitly specified when executing a given caching function.
    |
    */

    'default' => env('CACHE_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    */

    'stores' => [

        'apc' => [
            'driver' => 'apc',
        ],

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => null,
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path().'/framework/cache/data',
        ],

        'memcached' => [
            'driver' => 'memcached',
            'servers' => [
                [
                    'host' => '127.0.0.1', 'port' => 11211, 'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing a RAM based store such as APC or Memcached, there might
    | be other applications utilizing the same cache. So, we'll specify a
    | value to get prefixed to all our keys so we can avoid collisions.
    |
    */

    'prefix' => env(
        'CACHE_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_cache'
    ),

    /*
    |--------------------------------------------------------------------------
    | Cache Deserialization Allow-List
    |--------------------------------------------------------------------------
    |
    | When set to an array, this restricts which classes PHP is permitted to
    | rehydrate from cache via unserialize(). Anything not on the list comes
    | back as __PHP_Incomplete_Class. This is defence-in-depth against PHP
    | deserialization gadget chains: even if an attacker manages to write
    | hostile payloads into our cache store, they cannot instantiate
    | arbitrary classes during reads.
    |
    | Every class whose instances may be cached (directly or transitively
    | inside arrays, collections, or DTO properties) must appear here.
    | If you cache a new object type, add it to this list AND clear the
    | cache as part of deploy.
    |
    */

    'serializable_classes' => [
        // Application DTOs cached by the public read-model layer.
        PublicMeetingReadModel::class,
        PublicPageReadModel::class,

        // Podcast feed items cached by PodcastFeedService::getSermonsForFeed()
        // via Cache::flexible — the feed 500s on read-back without this.
        PodcastFeedItemReadModel::class,

        // Eloquent Page collections cached for the public navigation
        // (see App\View\Components\Layout\Header). Both the outer
        // collection class and the model class need allow-listing.
        Illuminate\Database\Eloquent\Collection::class,
        Collection::class,
        Page::class,

        // Preacher models cached by PreacherListCache::forPublicList()
        // for the public sermons browse filter and preachers index.
        Preacher::class,

        // Sermon collections cached by SermonRepository (preacher/service/series
        // listings via Cache::flexible), with their eager-loaded ScripturePassage
        // relation. Both must rehydrate or the public sermon pages 500 on read-back.
        Sermon::class,
        ScripturePassage::class,

        // CalendarEvent models live inside PublicMeetingReadModel::upcomingEvents
        // (see PublicMeetingReadModelCache). The CalendarEventStatus enum is a
        // model cast and must rehydrate as an enum, not __PHP_Incomplete_Class.
        CalendarEvent::class,
        CalendarEventStatus::class,

        // Enum cast on Page::area — required so the area attribute
        // rehydrates as an enum rather than an incomplete class.
        PageArea::class,

        // Carbon datetimes appear on every Eloquent model
        // (created_at / updated_at).
        Carbon::class,
        Illuminate\Support\Carbon::class,
        DateTime::class,
        DateTimeImmutable::class,
        DateTimeZone::class,
    ],

];
