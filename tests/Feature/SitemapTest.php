<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Enums\SermonContentType;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Public\SitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSlugViolatingSermons;
use Tests\TestCase;

#[Group('dedicated')]
class SitemapTest extends TestCase
{
    use CreatesSlugViolatingSermons;
    use RefreshDatabase;

    // Tests rely on seeded data (pages, sermons, meetings)
    // Seeding on every test is slow but ensures data isolation
    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Remove the shared sitemap file to prevent parallel test interference.
        @unlink(public_path('sitemap.xml'));
    }

    protected function tearDown(): void
    {
        @unlink(public_path('sitemap.xml'));

        parent::tearDown();
    }

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
        $this->assertStringContainsString('<loc>http://localhost/christmas</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/church</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/community</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/calendar</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ/sermons</loc>', $content);
        $this->assertStringNotContainsString('<loc>http://localhost/christ/sermons/all</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ/sermons/preachers</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ/sermons/series</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ/sermons/morning</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ/sermons/evening</loc>', $content);
    }

    #[Test]
    public function sitemap_contains_sermon_urls_with_date_format(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-01-15',
        ]);

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        // Check for date-based URL format
        $this->assertStringContainsString(
            '<loc>http://localhost/christ/sermons/2024/01/test-sermon</loc>',
            $content
        );
    }

    #[Test]
    public function sitemap_includes_preacher_urls(): void
    {
        Preacher::factory()->create([
            'slug' => 'test-preacher',
            'is_active' => true,
        ]);

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        $this->assertStringContainsString(
            '<loc>http://localhost/christ/sermons/preachers/test-preacher</loc>',
            $content
        );
    }

    #[Test]
    public function sitemap_includes_series_urls(): void
    {
        Sermon::factory()->create([
            'series' => 'Test Series',
        ]);

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        $this->assertStringContainsString(
            '<loc>http://localhost/christ/sermons/series/test-series</loc>',
            $content
        );
    }

    #[Test]
    public function sitemap_excludes_childrens_talks_while_public_release_is_disabled(): void
    {
        Sermon::factory()->create([
            'slug' => 'hidden-childrens-talk',
            'date' => '2026-02-15',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        $this->assertStringNotContainsString('/christ/childrens-corner', $content);
        $this->assertStringNotContainsString('hidden-childrens-talk', $content);
    }

    #[Test]
    public function sitemap_uses_childrens_corner_urls_when_public_release_is_enabled(): void
    {
        config(['church.sermons.childrens_talks.public' => true]);

        Sermon::factory()->create([
            'slug' => 'public-childrens-talk',
            'date' => '2026-02-15',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        $this->assertStringContainsString('<loc>http://localhost/christ/childrens-corner</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ/childrens-corner/public-childrens-talk</loc>', $content);
        $this->assertStringNotContainsString('/christ/sermons/2026/02/public-childrens-talk', $content);
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

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        $this->assertStringContainsString(
            '<loc>http://localhost/community/test-meeting</loc>',
            $content
        );
    }

    #[Test]
    public function sitemap_does_not_duplicate_a_community_page_that_shares_a_meeting_slug(): void
    {
        // Production meetings are not reliably linked to their page via page_id, so
        // a community Page and a Meeting can share a slug while the meeting() FK is
        // null. Both resolve to the same /community/{slug} URL; the page entry must
        // be suppressed so the sitemap emits that URL exactly once (the meeting).
        Page::factory()->create([
            'slug' => 'shared-slug-club',
            'area' => PageArea::Community->value,
            'admin' => 'no',
        ]);
        Meeting::factory()->create([
            'slug' => 'shared-slug-club',
            'page_id' => null,
        ]);

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        $this->assertSame(
            1,
            substr_count($content, '<loc>http://localhost/community/shared-slug-club</loc>'),
            'The shared /community/{slug} URL should appear exactly once in the sitemap.'
        );
    }

    #[Test]
    public function sitemap_does_not_duplicate_the_christ_sermons_index_page(): void
    {
        // /christ/sermons is the canonical sermons index, emitted by addStaticUrls()
        // via route('sermons.index'). It also exists as a public Page (area christ,
        // slug sermons), so addPages() must suppress it to avoid a duplicate <loc>.
        // Guarantee the precondition explicitly rather than relying on $seed, which is
        // not re-applied when a parallel worker has already migrated via a non-seeding
        // test — the (area, slug) unique constraint keeps this to one row either way.
        if (! Page::query()->where('area', PageArea::Christ->value)->where('slug', 'sermons')->exists()) {
            Page::factory()->create([
                'area' => PageArea::Christ->value,
                'slug' => 'sermons',
            ]);
        }

        $response = $this->get('/sitemap.xml');
        $content = $response->getContent();

        $this->assertSame(
            1,
            substr_count($content, '<loc>http://localhost/christ/sermons</loc>'),
            'The canonical /christ/sermons index URL should appear exactly once in the sitemap.'
        );
    }

    #[Test]
    public function sitemap_includes_last_modification_dates(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'dated-sermon',
            'date' => '2024-01-15',
        ]);

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
    public function sitemap_file_is_created_in_public_directory(): void
    {
        $sitemapService = app(SitemapService::class);

        $this->get('/sitemap.xml');

        $filePath = $sitemapService->getFilePath();
        $this->assertFileExists($filePath);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    #[Test]
    public function sitemap_route_is_named_correctly(): void
    {
        $this->assertTrue(
            Route::has('sitemap'),
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

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);

        $content = $response->getContent();

        // Should still contain static URLs
        $this->assertStringContainsString('<loc>http://localhost</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/christ</loc>', $content);

    }

    #[Test]
    public function sitemap_includes_pages(): void
    {
        $page = Page::factory()->create([
            'slug' => 'page-without-date',
            'area' => 'church',
            'admin' => 'no',
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('/church/page-without-date', $content);
    }

    #[Test]
    public function sitemap_includes_meetings(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'meeting-without-date',
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('/community/meeting-without-date', $content);
    }

    #[Test]
    public function generate_command_writes_a_static_file_with_the_canary_marker(): void
    {
        // The deploy pre-builds the sitemap with `artisan sitemap:generate` so the
        // post-deploy canary hits a ready static file instead of generating it
        // synchronously inside the request. Guard the contract that command relies
        // on: it writes a readable file containing the `<urlset` marker the canary
        // greps for.
        $sitemapService = app(SitemapService::class);
        $filePath = $sitemapService->getFilePath();

        $this->artisan('sitemap:generate')->assertSuccessful();

        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        $this->assertNotFalse($content);
        $this->assertStringContainsString('<urlset', $content);
    }

    #[Test]
    public function generate_command_skips_a_sermon_without_a_slug(): void
    {
        // Production carries legacy sermons with a blank slug (the slug-format CHECK
        // constraint was skipped when added against violating data). Such a sermon
        // has no canonical dated URL; before the fix it reached the
        // visible-in-sitemap scope and threw UrlGenerationException, failing the
        // deploy's `sitemap:generate` step. It must now be skipped.
        $this->createSermonWithBlankSlug(['content_type' => SermonContentType::Sermon]);
        Sermon::factory()->create([
            'slug' => 'has-a-slug',
            'content_type' => SermonContentType::Sermon,
            'date' => '2025-05-20',
        ]);

        $this->artisan('sitemap:generate')->assertSuccessful();

        $content = file_get_contents(app(SitemapService::class)->getFilePath());
        $this->assertNotFalse($content);
        $this->assertStringContainsString('/2025/05/has-a-slug', $content);
    }
}
