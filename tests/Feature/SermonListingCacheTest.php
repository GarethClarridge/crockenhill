<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonListingCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    #[Test]
    public function series_page_caches_sermons(): void
    {
        Sermon::factory()->create(['series' => 'Genesis']);
        $url = '/christ/sermons/series/genesis';

        // Warm the cache
        $this->get($url)->assertOk();

        // Verify caching via behavior: second request should perform no sermon queries
        DB::enableQueryLog();
        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertCount(0, $sermonQueries, 'Sermons should be retrieved from cache, not database');
        DB::disableQueryLog();
    }

    #[Test]
    public function service_page_caches_sermons(): void
    {
        Sermon::factory()->create(['service' => 'morning']);
        $url = '/christ/sermons/morning';

        // Warm the cache
        $this->get($url)->assertOk();

        // Verify caching via behavior: second request should perform no sermon queries
        DB::enableQueryLog();
        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertCount(0, $sermonQueries, 'Sermons should be retrieved from cache, not database');
        DB::disableQueryLog();
    }

    #[Test]
    public function series_cache_is_invalidated_when_sermon_in_series_is_updated(): void
    {
        $sermon = Sermon::factory()->create(['series' => 'Genesis']);
        $url = '/christ/sermons/series/genesis';

        // Warm the cache
        $this->get($url)->assertOk();

        $sermon->update(['title' => 'Updated Genesis Sermon']);

        // Verify invalidation: next request should re-query the database
        DB::enableQueryLog();
        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertNotEmpty($sermonQueries, 'Sermon cache should have been invalidated');
        DB::disableQueryLog();
    }

    #[Test]
    public function service_cache_is_invalidated_when_sermon_in_service_is_deleted(): void
    {
        $sermon = Sermon::factory()->create(['service' => 'morning']);
        $url = '/christ/sermons/morning';

        // Warm the cache
        $this->get($url)->assertOk();

        $sermon->delete();

        // Verify invalidation: next request should re-query the database
        DB::enableQueryLog();
        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertNotEmpty($sermonQueries, 'Sermon cache should have been invalidated');
        DB::disableQueryLog();
    }
}
