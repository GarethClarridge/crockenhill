<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Public;

use App\Enums\PageArea;
use App\Models\Page;
use App\Services\Public\PageListCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageListCacheTest extends TestCase
{
    use DatabaseTransactions;

    private PageListCache $service;

    protected function setUp(): void
    {
        parent::setUp();
        Page::query()->delete();
        $this->service = app(PageListCache::class);
        Cache::flush();
    }

    #[Test]
    public function get_all_links_for_area_returns_correct_pages_and_caches_them(): void
    {
        $area = PageArea::Church;
        $page1 = Page::factory()->create(['area' => $area, 'admin' => 'no']);
        $page2 = Page::factory()->create(['area' => $area, 'admin' => 'no']);
        $privatePage = Page::factory()->create(['area' => $area, 'admin' => 'yes']);
        $otherAreaPage = Page::factory()->create(['area' => PageArea::Community, 'admin' => 'no']);

        $results = $this->service->getAllLinksForArea($area);

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains($page1));
        $this->assertTrue($results->contains($page2));
        $this->assertFalse($results->contains($privatePage));
        $this->assertFalse($results->contains($otherAreaPage));

        // Test flexible caching.
        DB::enableQueryLog();
        $this->service->getAllLinksForArea($area);
        $this->assertEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function get_links_for_area_slugs_returns_ordered_pages_and_caches_them(): void
    {
        $area = PageArea::Church;
        $pageA = Page::factory()->create(['area' => $area, 'slug' => 'slug-a', 'admin' => 'no']);
        $pageB = Page::factory()->create(['area' => $area, 'slug' => 'slug-b', 'admin' => 'no']);
        $pageC = Page::factory()->create(['area' => $area, 'slug' => 'slug-c', 'admin' => 'no']);

        $slugs = ['slug-c', 'slug-a'];
        $cacheKey = 'test_cache_key_area_slugs';

        $results = $this->service->getLinksForAreaSlugs($area, $slugs, $cacheKey);

        $this->assertCount(2, $results);
        $this->assertEquals('slug-c', $results[0]->slug);
        $this->assertEquals('slug-a', $results[1]->slug);

        // Test flexible caching.
        DB::enableQueryLog();
        $this->service->getLinksForAreaSlugs($area, $slugs, $cacheKey);
        $this->assertEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function get_links_by_slugs_returns_ordered_pages_across_areas_and_caches_them(): void
    {
        $page1 = Page::factory()->create(['area' => PageArea::Church, 'slug' => 'slug-1', 'admin' => 'no']);
        $page2 = Page::factory()->create(['area' => PageArea::Community, 'slug' => 'slug-2', 'admin' => 'no']);
        $memberPage = Page::factory()->create(['area' => PageArea::Members, 'slug' => 'slug-3', 'admin' => 'no']);

        $slugs = ['slug-2', 'slug-1', 'slug-3'];
        $cacheKey = 'test_cache_key_by_slugs';

        $results = $this->service->getLinksBySlugs($slugs, $cacheKey);

        $this->assertCount(2, $results);
        $this->assertEquals('slug-2', $results[0]->slug);
        $this->assertEquals('slug-1', $results[1]->slug);
        $this->assertFalse($results->contains($memberPage));

        // Test flexible caching.
        DB::enableQueryLog();
        $this->service->getLinksBySlugs($slugs, $cacheKey);
        $this->assertEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function flushing_the_cache_forces_a_fresh_query(): void
    {
        $area = PageArea::Church;
        Page::factory()->create(['area' => $area, 'admin' => 'no']);

        $this->service->getAllLinksForArea($area);

        // Verify caching works.
        DB::enableQueryLog();
        $this->service->getAllLinksForArea($area);
        $this->assertEmpty(DB::getQueryLog());

        Cache::flush();

        $this->service->getAllLinksForArea($area);
        $this->assertNotEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function flexible_cache_entries_are_scoped_by_cache_key(): void
    {
        $page1 = Page::factory()->create(['area' => PageArea::Church, 'slug' => 'slug-1', 'admin' => 'no']);

        $results1 = $this->service->getLinksBySlugs(['slug-1'], 'key-1');
        $results2 = $this->service->getLinksBySlugs(['slug-1'], 'key-2');

        $this->assertNotSame($results1, $results2); // Different collections due to different cache keys
    }
}
