<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('dedicated')]
class SitemapCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        DB::enableQueryLog();

        $sitemapService = app(\App\Services\Public\SitemapService::class);
        @unlink($sitemapService->getFilePath());

        Cache::forget('sitemap');
        Cache::forget('nav_pages');
    }

    protected function tearDown(): void
    {
        $sitemapService = app(\App\Services\Public\SitemapService::class);
        @unlink($sitemapService->getFilePath());

        parent::tearDown();
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_sermon_is_created(): void
    {
        // Generate initial sitemap
        $this->get('/sitemap.xml');

        // Verify next request is cached (zero DB queries)
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Create a new sermon
        Sermon::factory()->create([
            'slug' => 'new-sermon',
            'date' => now(),
        ]);

        // Request again, should hit DB because cache was invalidated
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after sermon creation.');
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_sermon_is_updated(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => now(),
        ]);

        // Generate sitemap
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Update the sermon
        $sermon->update(['title' => 'Updated Title']);

        // Request again, should hit DB
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after sermon update.');
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_sermon_is_deleted(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => now(),
        ]);

        // Generate sitemap
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Delete the sermon
        $sermon->delete();

        // Request again, should hit DB
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after sermon deletion.');
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_page_is_created(): void
    {
        // Generate initial sitemap
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Create a new page
        Page::factory()->create([
            'slug' => 'new-page',
            'area' => 'church',
            'admin' => 'no',
        ]);

        // Request again, should hit DB
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after page creation.');
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
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Update the page
        $page->update(['heading' => 'Updated Heading']);

        // Request again, should hit DB
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after page update.');
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
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Delete the page
        $page->delete();

        // Request again, should hit DB
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after page deletion.');
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_meeting_is_created(): void
    {
        // Generate initial sitemap
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Create a new meeting
        Meeting::factory()->create([
            'slug' => 'new-meeting',
        ]);

        // Request again, should hit DB
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after meeting creation.');
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_meeting_is_updated(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        // Generate sitemap
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Update the meeting
        $meeting->update(['location' => 'Updated Location']);

        // Request again, should hit DB
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after meeting update.');
    }

    #[Test]
    public function sitemap_cache_is_invalidated_when_meeting_is_deleted(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        // Generate sitemap
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Delete the meeting
        $meeting->delete();

        // Request again, should hit DB
        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after meeting deletion.');
    }

    #[Test]
    public function sitemap_regenerates_with_new_content_after_cache_invalidation(): void
    {
        // Generate initial sitemap
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
        $this->get('/');

        DB::flushQueryLog();
        $this->get('/');
        $this->assertCount(0, DB::getQueryLog(), 'Header navigation should be retrieved from cache.');
    }

    #[Test]
    public function nav_pages_cache_is_invalidated_when_page_is_created(): void
    {
        $this->get('/');

        DB::flushQueryLog();
        $this->get('/');
        $this->assertCount(0, DB::getQueryLog(), 'Header navigation should be retrieved from cache.');

        Page::factory()->create(['slug' => 'new-nav-page', 'area' => 'church', 'admin' => 'no']);

        DB::flushQueryLog();
        $this->get('/');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Header navigation should hit database after page creation.');
    }

    #[Test]
    public function nav_pages_cache_is_invalidated_when_page_is_updated(): void
    {
        $page = Page::factory()->create(['slug' => 'nav-page', 'area' => 'church', 'admin' => 'no']);

        $this->get('/');

        DB::flushQueryLog();
        $this->get('/');
        $this->assertCount(0, DB::getQueryLog(), 'Header navigation should be retrieved from cache.');

        $page->update(['heading' => 'Updated Heading']);

        DB::flushQueryLog();
        $this->get('/');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Header navigation should hit database after page update.');
    }

    #[Test]
    public function nav_pages_cache_is_invalidated_when_page_is_deleted(): void
    {
        $page = Page::factory()->create(['slug' => 'nav-page', 'area' => 'church', 'admin' => 'no']);

        $this->get('/');

        DB::flushQueryLog();
        $this->get('/');
        $this->assertCount(0, DB::getQueryLog(), 'Header navigation should be retrieved from cache.');

        $page->delete();

        DB::flushQueryLog();
        $this->get('/');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Header navigation should hit database after page deletion.');
    }

    #[Test]
    public function multiple_model_changes_only_invalidate_cache_once(): void
    {
        // Generate initial sitemap
        $this->get('/sitemap.xml');

        DB::flushQueryLog();
        $this->get('/sitemap.xml');
        $this->assertCount(0, DB::getQueryLog(), 'Sitemap should be retrieved from cache.');

        // Create multiple models
        Sermon::factory()->create(['slug' => 'sermon-1', 'date' => now()]);
        Page::factory()->create(['slug' => 'page-1', 'area' => 'church', 'admin' => 'no']);
        Meeting::factory()->create(['slug' => 'meeting-1']);

        // Request again
        DB::flushQueryLog();
        $response = $this->get('/sitemap.xml');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Sitemap should hit database after multiple changes.');

        // All new content should be present
        $content = $response->getContent();
        $this->assertStringContainsString('sermon-1', $content);
        $this->assertStringContainsString('page-1', $content);
        $this->assertStringContainsString('meeting-1', $content);
    }
}
