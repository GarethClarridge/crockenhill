<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use App\Services\Public\PageCardService;
use App\Services\Public\PageListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    #[Test]
    public function page_links_cache_is_populated_on_request(): void
    {
        $area = 'church';
        $repository = app(PageListCache::class);

        // Clear everything to start fresh
        Cache::flush();
        $repository->clearInternalCaches();

        DB::enableQueryLog();

        // First call should hit the database
        $repository->getAllLinksForArea($area);
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'pages'));
        $this->assertCount(1, $queries, 'Initial call should perform a database query on the pages table.');

        DB::flushQueryLog();
        $repository->clearInternalCaches();

        // Second call should NOT hit the database (served from cache)
        $repository->getAllLinksForArea($area);
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'pages'));
        $this->assertCount(0, $queries, 'Second call should be served from cache and perform no queries on the pages table.');
    }

    #[Test]
    public function page_links_cache_is_invalidated_when_page_is_created(): void
    {
        $area = 'church';
        $repository = app(PageListCache::class);

        Cache::flush();
        $repository->clearInternalCaches();

        // Populate cache
        $repository->getAllLinksForArea($area);

        // Create a new page - this should invalidate the cache
        Page::factory()->create([
            'slug' => 'new-page',
            'area' => $area,
            'admin' => 'no',
        ]);

        $repository->clearInternalCaches();
        DB::enableQueryLog();

        // Call again - should hit the database because cache was invalidated
        $repository->getAllLinksForArea($area);
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'pages'));
        $this->assertCount(1, $queries, 'Call after creation should hit the database because cache was invalidated.');
    }

    #[Test]
    public function page_links_cache_is_invalidated_when_page_is_updated(): void
    {
        $area = 'church';
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'area' => $area,
            'admin' => 'no',
        ]);

        $repository = app(PageListCache::class);
        Cache::flush();
        $repository->clearInternalCaches();

        // Populate cache
        $repository->getAllLinksForArea($area);

        // Update the page - this should invalidate the cache
        $page->update(['heading' => 'Updated Heading']);

        $repository->clearInternalCaches();
        DB::enableQueryLog();

        // Call again - should hit the database
        $repository->getAllLinksForArea($area);
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'pages'));
        $this->assertCount(1, $queries, 'Call after update should hit the database because cache was invalidated.');
    }

    #[Test]
    public function page_links_cache_is_invalidated_when_page_is_deleted(): void
    {
        $area = 'church';
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'area' => $area,
            'admin' => 'no',
        ]);

        $repository = app(PageListCache::class);
        Cache::flush();
        $repository->clearInternalCaches();

        // Populate cache
        $repository->getAllLinksForArea($area);

        // Delete the page - this should invalidate the cache
        $page->delete();

        $repository->clearInternalCaches();
        DB::enableQueryLog();

        // Call again - should hit the database
        $repository->getAllLinksForArea($area);
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'pages'));
        $this->assertCount(1, $queries, 'Call after deletion should hit the database because cache was invalidated.');
    }

    #[Test]
    public function page_links_cache_is_area_specific(): void
    {
        $repository = app(PageListCache::class);
        Cache::flush();
        $repository->clearInternalCaches();

        // Populate caches for both areas
        $repository->getAllLinksForArea('church');
        $repository->getAllLinksForArea('community');

        // Update a page in 'church' area
        $page = Page::where('area', 'church')->first()
            ?? Page::factory()->create(['area' => 'church', 'slug' => 'cache-test-church', 'admin' => 'no']);
        $page->update(['heading' => 'New Heading']);

        $repository->clearInternalCaches();
        DB::enableQueryLog();

        // Church area should hit DB
        $repository->getAllLinksForArea('church');
        $churchQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'pages'));
        $this->assertCount(1, $churchQueries, 'Church cache should have been invalidated.');

        DB::flushQueryLog();
        $repository->clearInternalCaches();

        // Community area should NOT hit DB
        $repository->getAllLinksForArea('community');
        $communityQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'pages'));
        $this->assertCount(0, $communityQueries, 'Community cache should NOT have been invalidated.');
    }

    #[Test]
    public function page_card_rail_caches_are_invalidated_when_pages_change(): void
    {
        $pageCardService = app(PageCardService::class);
        $pageListCache = app(PageListCache::class);

        Cache::flush();
        $pageListCache->clearInternalCaches();

        Page::query()->where('slug', 'sunday-evenings')->delete();

        $page = Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => 'community',
            'admin' => 'no',
        ]);

        // Populate cache
        $pageCardService->forHome();

        // Update the page - this should invalidate the cache
        $page->update(['heading' => 'Updated Sunday Evenings']);

        $pageListCache->clearInternalCaches();
        DB::enableQueryLog();

        // Call again - should hit the database
        $pageCardService->forHome();
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'pages'));
        $this->assertGreaterThan(0, $queries->count(), 'Call after change should hit the database because cache was invalidated.');
    }
}
