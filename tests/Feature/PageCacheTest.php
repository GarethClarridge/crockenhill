<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use App\Repositories\PageRepository;
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
        $repository = app(PageRepository::class);
        $repository->getAllLinksForArea($area);

        $this->assertTrue(Cache::has("page_links_{$area}"));
    }

    #[Test]
    public function page_links_cache_is_invalidated_when_page_is_created(): void
    {
        $area = 'church';
        $repository = app(PageRepository::class);
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

        $repository = app(PageRepository::class);
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

        $repository = app(PageRepository::class);
        $repository->getAllLinksForArea($area);

        $this->assertTrue(Cache::has("page_links_{$area}"));

        $page->delete();

        $this->assertFalse(Cache::has("page_links_{$area}"));
    }

    #[Test]
    public function page_links_cache_is_area_specific(): void
    {
        $repository = app(PageRepository::class);

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
}
