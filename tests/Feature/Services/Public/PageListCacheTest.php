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
        $this->service = app(PageListCache::class);
        Cache::flush();
    }

    #[Test]
    public function get_all_links_for_area_returns_correct_pages_and_memoizes(): void
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

        // Test Memoization
        DB::enableQueryLog();
        $this->service->getAllLinksForArea($area);
        $this->assertEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function get_links_for_area_slugs_returns_ordered_pages_and_memoizes(): void
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

        // Test Memoization
        DB::enableQueryLog();
        $this->service->getLinksForAreaSlugs($area, $slugs, $cacheKey);
        $this->assertEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function get_links_by_slugs_returns_ordered_pages_across_areas_and_memoizes(): void
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

        // Test Memoization
        DB::enableQueryLog();
        $this->service->getLinksBySlugs($slugs, $cacheKey);
        $this->assertEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function clear_internal_caches_resets_memoization(): void
    {
        $area = PageArea::Church;
        Page::factory()->create(['area' => $area, 'admin' => 'no']);

        $this->service->getAllLinksForArea($area);

        // Verify memoization works
        DB::enableQueryLog();
        $this->service->getAllLinksForArea($area);
        $this->assertEmpty(DB::getQueryLog());

        // Clear internal memoization
        $this->service->clearInternalCaches();

        // Clear Laravel cache to ensure it hits DB
        Cache::flush();

        $this->service->getAllLinksForArea($area);
        $this->assertNotEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function clear_area_cache_clears_laravel_cache_and_internal_memoization(): void
    {
        $area = PageArea::Church;
        Page::factory()->create(['area' => $area, 'admin' => 'no']);

        $this->service->getAllLinksForArea($area);

        // Populate Cache by clearing internal only
        $this->service->clearInternalCaches();
        $this->service->getAllLinksForArea($area); // Hits Cache

        DB::enableQueryLog();

        // 1. Clear Area Cache
        $this->service->clearAreaCache($area);

        // 2. Next call should hit DB because Cache was cleared
        $this->service->getAllLinksForArea($area);
        $this->assertNotEmpty(DB::getQueryLog());

        DB::disableQueryLog();
    }

    #[Test]
    public function internal_memoization_is_scoped_by_cache_key(): void
    {
        $page1 = Page::factory()->create(['area' => PageArea::Church, 'slug' => 'slug-1', 'admin' => 'no']);

        $results1 = $this->service->getLinksBySlugs(['slug-1'], 'key-1');
        $results2 = $this->service->getLinksBySlugs(['slug-1'], 'key-2');

        $this->assertNotSame($results1, $results2); // Different collections due to different cache keys
    }
}
