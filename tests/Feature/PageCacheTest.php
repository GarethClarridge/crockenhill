<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use App\Services\Public\PageCardService;
use App\Services\Public\PageListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        Cache::forget("page_links_{$area}");

        $this->assertFalse(Cache::has("page_links_{$area}"));

        // Use the repository directly to verify caching
        $repository = app(PageListCache::class);
        $repository->getAllLinksForArea($area);

        $this->assertTrue(Cache::has("page_links_{$area}"));
    }

    #[Test]
    public function page_links_cache_is_invalidated_when_page_is_created(): void
    {
        $area = 'church';
        $repository = app(PageListCache::class);
        $repository->getAllLinksForArea($area);

        $this->assertTrue(Cache::has("page_links_{$area}"));

        Page::factory()->create([
            'slug' => 'new-page',
            'area' => $area,
            'admin' => 'no',
        ]);

        $this->assertFalse(Cache::has("page_links_{$area}"));
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
        $repository->getAllLinksForArea($area);

        $this->assertTrue(Cache::has("page_links_{$area}"));

        $page->update(['heading' => 'Updated Heading']);

        $this->assertFalse(Cache::has("page_links_{$area}"));
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
        $repository->getAllLinksForArea($area);

        $this->assertTrue(Cache::has("page_links_{$area}"));

        $page->delete();

        $this->assertFalse(Cache::has("page_links_{$area}"));
    }

    #[Test]
    public function page_links_cache_is_area_specific(): void
    {
        $repository = app(PageListCache::class);

        $repository->getAllLinksForArea('church');
        $repository->getAllLinksForArea('community');

        $this->assertTrue(Cache::has('page_links_church'));
        $this->assertTrue(Cache::has('page_links_community'));

        // Update a page in 'church' area
        $page = Page::where('area', 'church')->first()
            ?? Page::factory()->create(['area' => 'church', 'slug' => 'cache-test-church', 'admin' => 'no']);
        $page->update(['heading' => 'New Heading']);

        $this->assertFalse(Cache::has('page_links_church'));
        $this->assertTrue(Cache::has('page_links_community'));
    }

    #[Test]
    public function page_card_rail_caches_are_invalidated_when_pages_change(): void
    {
        Cache::forget('page_card_rail_home');
        Cache::forget('illuminate:cache:flexible:created:page_card_rail_home');

        Page::query()->where('slug', 'sunday-evenings')->delete();

        $page = Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => 'community',
            'admin' => 'no',
        ]);

        app(PageCardService::class)->forHome();

        $this->assertTrue(Cache::has('page_card_rail_home'));

        $page->update(['heading' => 'Updated Sunday Evenings']);

        $this->assertFalse(Cache::has('page_card_rail_home'));
    }
}
