<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Services\Public\SermonRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonListingCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        DB::enableQueryLog();
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        parent::tearDown();
    }

    #[Test]
    public function series_page_caches_sermons(): void
    {
        Sermon::factory()->create(['series' => 'Genesis']);
        $url = '/christ/sermons/series/genesis';

        $this->get($url)->assertOk();

        app(SermonRepository::class)->clearInternalCaches();
        DB::flushQueryLog();

        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertCount(0, $sermonQueries, 'Sermons should be retrieved from cache, not database');
    }

    #[Test]
    public function service_page_caches_sermons(): void
    {
        Sermon::factory()->create(['service' => 'morning']);
        $url = '/christ/sermons/morning';

        $this->get($url)->assertOk();

        app(SermonRepository::class)->clearInternalCaches();
        DB::flushQueryLog();

        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertCount(0, $sermonQueries, 'Sermons should be retrieved from cache, not database');
    }

    #[Test]
    public function series_cache_is_invalidated_when_sermon_in_series_is_updated(): void
    {
        $sermon = Sermon::factory()->create(['series' => 'Genesis']);
        $url = '/christ/sermons/series/genesis';

        $this->get($url)->assertOk();

        $sermon->update(['title' => 'Updated Genesis Sermon']);

        DB::flushQueryLog();

        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertNotEmpty($sermonQueries, 'Sermon cache should have been invalidated');
    }

    #[Test]
    public function service_cache_is_invalidated_when_sermon_in_service_is_deleted(): void
    {
        $sermon = Sermon::factory()->create(['service' => 'morning']);
        $url = '/christ/sermons/morning';

        $this->get($url)->assertOk();

        $sermon->delete();

        DB::flushQueryLog();

        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertNotEmpty($sermonQueries, 'Sermon cache should have been invalidated');
    }
}
