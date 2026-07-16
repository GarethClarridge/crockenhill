<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        Cache::flush();
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

        DB::flushQueryLog();

        $this->get($url)->assertOk();

        $sermonQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains((string) $query['query'], 'sermons'));

        $this->assertCount(0, $sermonQueries, 'Sermons should be retrieved from cache, not database');
    }
}
