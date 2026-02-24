<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitemapCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    #[Test]
    public function sitemap_cache_is_invalidated_when_sermon_is_created(): void
    {
        // Generate initial sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        // Cache should exist
        $this->assertTrue(Cache::has('sitemap'));

        // Create a new sermon
        Sermon::factory()->create([
            'slug' => 'new-sermon',
            'date' => now(),
        ]);

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_sermon_is_updated(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => now(),
        ]);

        // Generate sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Update the sermon
        $sermon->update(['title' => 'Updated Title']);

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_sermon_is_deleted(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => now(),
        ]);

        // Generate sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Delete the sermon
        $sermon->delete();

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_page_is_created(): void
    {
        // Generate initial sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Create a new page
        Page::factory()->create([
            'slug' => 'new-page',
            'area' => 'church',
            'admin' => 'no',
        ]);

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_page_is_updated(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'area' => 'church',
            'admin' => 'no',
        ]);

        // Generate sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Update the page
        $page->update(['heading' => 'Updated Heading']);

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_page_is_deleted(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'area' => 'church',
            'admin' => 'no',
        ]);

        // Generate sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Delete the page
        $page->delete();

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_meeting_is_created(): void
    {
        // Generate initial sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Create a new meeting
        Meeting::factory()->create([
            'slug' => 'new-meeting',
        ]);

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_meeting_is_updated(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        // Generate sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Update the meeting
        $meeting->update(['location' => 'Updated Location']);

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_meeting_is_deleted(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        // Generate sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Delete the meeting
        $meeting->delete();

        // Cache should be invalidated
        $this->assertFalse(Cache::has('sitemap'));
    }

    #[Test]
    public function sitemap_regenerates_with_new_content_after_cache_invalidation(): void
    {
        // Generate initial sitemap
        Cache::forget('sitemap');
        $response1 = $this->get('/sitemap.xml');
        $content1 = $response1->getContent();

        // Should not contain new sermon
        $this->assertStringNotContainsString('brand-new-sermon', $content1);

        // Create a new sermon (this invalidates cache)
        Sermon::factory()->create([
            'slug' => 'brand-new-sermon',
            'date' => now(),
        ]);

        // Get sitemap again
        $response2 = $this->get('/sitemap.xml');
        $content2 = $response2->getContent();

        // Should now contain the new sermon
        $this->assertStringContainsString('brand-new-sermon', $content2);
    }

    #[Test]
    public function nav_pages_cache_is_populated_on_first_header_request(): void
    {
        Cache::forget('nav_pages');

        $this->assertFalse(Cache::has('nav_pages'));

        $this->get('/');

        $this->assertTrue(Cache::has('nav_pages'));
    }

    #[Test]
    public function nav_pages_cache_is_invalidated_when_page_is_created(): void
    {
        $this->get('/');
        $this->assertTrue(Cache::has('nav_pages'));

        Page::factory()->create(['slug' => 'new-nav-page', 'area' => 'church', 'admin' => 'no']);

        $this->assertFalse(Cache::has('nav_pages'));
    }

    #[Test]
    public function nav_pages_cache_is_invalidated_when_page_is_updated(): void
    {
        $page = Page::factory()->create(['slug' => 'nav-page', 'area' => 'church', 'admin' => 'no']);

        $this->get('/');
        $this->assertTrue(Cache::has('nav_pages'));

        $page->update(['heading' => 'Updated Heading']);

        $this->assertFalse(Cache::has('nav_pages'));
    }

    #[Test]
    public function nav_pages_cache_is_invalidated_when_page_is_deleted(): void
    {
        $page = Page::factory()->create(['slug' => 'nav-page', 'area' => 'church', 'admin' => 'no']);

        $this->get('/');
        $this->assertTrue(Cache::has('nav_pages'));

        $page->delete();

        $this->assertFalse(Cache::has('nav_pages'));
    }

    #[Test]
    public function multiple_model_changes_only_invalidate_cache_once(): void
    {
        // Generate initial sitemap
        Cache::forget('sitemap');
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap'));

        // Create multiple models
        Sermon::factory()->create(['slug' => 'sermon-1', 'date' => now()]);
        Page::factory()->create(['slug' => 'page-1', 'area' => 'church', 'admin' => 'no']);
        Meeting::factory()->create(['slug' => 'meeting-1']);

        // Cache should still be gone (invalidated by first change)
        $this->assertFalse(Cache::has('sitemap'));

        // Regenerate sitemap
        $response = $this->get('/sitemap.xml');

        // All new content should be present
        $content = $response->getContent();
        $this->assertStringContainsString('sermon-1', $content);
        $this->assertStringContainsString('page-1', $content);
        $this->assertStringContainsString('meeting-1', $content);
    }
}
