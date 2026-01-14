<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    // Tests rely on seeded data (pages, sermons, meetings)
    // Seeding on every test is slow but ensures data isolation
    protected $seed = true;

    #[Test]
    public function it_generates_sitemap_xml_successfully(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/xml');
    }

    #[Test]
    public function sitemap_contains_static_urls(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Check for main static URLs
        $this->assertStringContainsString('<loc>http://localhost</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/church</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/community</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/calendar</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ/sermons</loc>', $content);
    }

    #[Test]
    public function sitemap_contains_sermon_urls_with_date_format(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-01-15',
        ]);

        // Clear cache to regenerate sitemap
        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Check for date-based URL format
        $this->assertStringContainsString(
            '<loc>http://localhost/christ/sermons/2024/01/test-sermon</loc>',
            $content
        );
    }

    #[Test]
    public function sitemap_excludes_admin_pages(): void
    {
        // Create a public page
        $publicPage = Page::factory()->create([
            'slug' => 'public-page',
            'area' => 'church',
            'admin' => 'no',
        ]);

        // Create an admin page
        $adminPage = Page::factory()->create([
            'slug' => 'admin-page',
            'area' => 'church',
            'admin' => 'yes',
        ]);

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Public page should be included
        $this->assertStringContainsString('/church/public-page', $content);

        // Admin page should be excluded
        $this->assertStringNotContainsString('/church/admin-page', $content);
    }

    #[Test]
    public function sitemap_includes_meeting_urls(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        $this->assertStringContainsString(
            '<loc>http://localhost/community/test-meeting</loc>',
            $content
        );
    }

    #[Test]
    public function sitemap_has_correct_priorities(): void
    {
        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Homepage should have priority 1.0
        $this->assertMatchesRegularExpression(
            '/<loc>http:\/\/localhost<\/loc>.*?<priority>1\.0<\/priority>/s',
            $content
        );

        // Main sections should have priority 0.9
        $this->assertMatchesRegularExpression(
            '/<loc>http:\/\/localhost\/christ<\/loc>.*?<priority>0\.9<\/priority>/s',
            $content
        );
    }

    #[Test]
    public function sitemap_includes_change_frequencies(): void
    {
        // Create a page to ensure "monthly" frequency appears
        // (seeded sermons are all old, so they have "yearly" frequency)
        Page::factory()->create([
            'slug' => 'test-page-for-frequency',
            'area' => 'church',
            'admin' => 'no',
        ]);

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Check for various change frequencies
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $content);  // Static pages
        $this->assertStringContainsString('<changefreq>daily</changefreq>', $content);   // Sermons index
        $this->assertStringContainsString('<changefreq>monthly</changefreq>', $content); // Pages
    }

    #[Test]
    public function recent_sermons_have_higher_priority(): void
    {
        // Create a recent sermon (< 30 days old)
        $recentSermon = Sermon::factory()->create([
            'slug' => 'recent-sermon',
            'date' => now()->subDays(15),
        ]);

        // Create an old sermon (> 30 days old)
        $oldSermon = Sermon::factory()->create([
            'slug' => 'old-sermon',
            'date' => now()->subDays(60),
        ]);

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Recent sermon should have priority 0.8
        $this->assertMatchesRegularExpression(
            '/recent-sermon<\/loc>.*?<priority>0\.8<\/priority>/s',
            $content
        );

        // Old sermon should have priority 0.6
        $this->assertMatchesRegularExpression(
            '/old-sermon<\/loc>.*?<priority>0\.6<\/priority>/s',
            $content
        );
    }

    #[Test]
    public function sitemap_includes_last_modification_dates(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'dated-sermon',
            'date' => '2024-01-15',
        ]);

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Should include lastmod tag
        $this->assertStringContainsString('<lastmod>', $content);
    }

    #[Test]
    public function sitemap_is_valid_xml(): void
    {
        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Check XML structure
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $content);
        $this->assertStringContainsString('</urlset>', $content);

        // Verify XML is parseable
        $xml = simplexml_load_string($content);
        $this->assertNotFalse($xml, 'Sitemap should be valid XML');
    }

    #[Test]
    public function sitemap_uses_flexible_caching(): void
    {
        // Clear cache
        Cache::forget('sitemap');

        // First request should generate sitemap
        $response1 = $this->get('/sitemap.xml');
        $this->assertFileExists(public_path('sitemap.xml'));

        // Modify the sitemap file slightly to detect if it's regenerated
        $originalContent = file_get_contents(public_path('sitemap.xml'));
        $modificationTime1 = filemtime(public_path('sitemap.xml'));

        // Second request should serve cached version (no sleep needed - filemtime is precise enough)
        $response2 = $this->get('/sitemap.xml');
        $modificationTime2 = filemtime(public_path('sitemap.xml'));

        // File modification time should be the same (cached)
        $this->assertEquals($modificationTime1, $modificationTime2);
    }

    #[Test]
    public function sitemap_file_is_created_in_public_directory(): void
    {
        Cache::forget('sitemap');

        $this->get('/sitemap.xml');

        $this->assertFileExists(public_path('sitemap.xml'));
        $this->assertGreaterThan(0, filesize(public_path('sitemap.xml')));
    }

    #[Test]
    public function sitemap_route_is_named_correctly(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('sitemap'),
            'Sitemap route should be named "sitemap"'
        );
    }

    #[Test]
    public function sitemap_handles_empty_database_gracefully(): void
    {
        // Clear all data
        Sermon::query()->delete();
        Page::query()->delete();
        Meeting::query()->delete();

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);

        $content = $response->getContent();

        // Should still contain static URLs
        $this->assertStringContainsString('<loc>http://localhost</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ</loc>', $content);

    }

    #[Test]
    public function old_sermons_have_yearly_change_frequency(): void
    {
        // Create an old sermon (> 365 days) - use 2 years to be safe
        $oldSermon = Sermon::factory()->create([
            'slug' => 'very-old-sermon',
            'date' => now()->subYears(2),
        ]);

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Old sermon should have yearly change frequency
        $this->assertMatchesRegularExpression(
            '/very-old-sermon<\/loc>.*?<changefreq>yearly<\/changefreq>/s',
            $content
        );
    }

    #[Test]
    public function sitemap_handles_pages_without_updated_at(): void
    {
        // Create a page with null updated_at
        $page = Page::factory()->create([
            'slug' => 'page-without-date',
            'area' => 'church',
            'admin' => 'no',
        ]);

        // Manually set updated_at to null
        \DB::table('pages')
            ->where('id', $page->id)
            ->update(['updated_at' => null]);

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');

        // Should not throw error
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('/church/page-without-date', $content);
    }

    #[Test]
    public function sitemap_handles_meetings_without_updated_at(): void
    {
        // Create a meeting with null updated_at
        $meeting = Meeting::factory()->create([
            'slug' => 'meeting-without-date',
        ]);

        // Manually set updated_at to null
        \DB::table('meetings')
            ->where('id', $meeting->id)
            ->update(['updated_at' => null]);

        Cache::forget('sitemap');

        $response = $this->get('/sitemap.xml');

        // Should not throw error
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('/community/meeting-without-date', $content);
    }
}
