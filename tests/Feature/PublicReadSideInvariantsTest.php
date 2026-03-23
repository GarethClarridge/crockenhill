<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use App\Models\User;
use App\Services\SermonExposurePolicy;
use App\Services\SitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TD-004A: Public read-side correctness, visibility, and canonical invariants.
 *
 * Covers:
 *  1. Unknown top-level area segments return 404.
 *  2. Meetings linked to admin-only pages are not publicly readable.
 *  3. Page-less meetings render correctly (no admin restriction).
 *  4. Public navigation never exposes admin-only pages.
 *  5. Canonical tags, sitemap, and HTML all agree on the same sermon URL.
 */
class PublicReadSideInvariantsTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // 1. Unknown top-level area segments return 404
    // -------------------------------------------------------------------------

    #[Test]
    public function unknown_top_level_segment_returns_404(): void
    {
        $this->get('/totally-unknown-area')->assertStatus(404);
    }

    #[Test]
    public function unknown_top_level_segment_with_sub_path_returns_404(): void
    {
        $this->get('/unknown-area/some-page')->assertStatus(404);
    }

    #[Test]
    public function known_areas_still_return_200(): void
    {
        // 'members' is intentionally excluded — it requires authentication and redirects guests.
        foreach (['christ', 'church', 'community'] as $area) {
            $this->get("/{$area}")->assertStatus(200);
        }
    }

    // -------------------------------------------------------------------------
    // 2. Meeting linked to an admin-only page is not publicly readable
    //    Guests are blocked; authenticated admins are allowed through (matching PageController).
    // -------------------------------------------------------------------------

    #[Test]
    public function meeting_linked_to_admin_page_returns_403_to_guests(): void
    {
        $adminPage = Page::factory()->create([
            'area' => PageArea::COMMUNITY,
            'slug' => 'restricted-meeting-page',
            'admin' => 'yes',
        ]);

        Meeting::factory()->create([
            'slug' => 'restricted-meeting',
            'page_id' => $adminPage->id,
        ]);

        $this->get('/community/restricted-meeting')->assertStatus(403);
    }

    #[Test]
    public function meeting_linked_to_admin_page_is_accessible_to_admin_users(): void
    {
        $adminPage = Page::factory()->create([
            'area' => PageArea::COMMUNITY,
            'slug' => 'admin-only-meeting-page',
            'admin' => 'yes',
        ]);

        Meeting::factory()->create([
            'slug' => 'admin-only-meeting',
            'page_id' => $adminPage->id,
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/community/admin-only-meeting')
            ->assertStatus(200);
    }

    #[Test]
    public function meeting_linked_to_public_page_is_accessible_to_guests(): void
    {
        $publicPage = Page::factory()->create([
            'area' => PageArea::COMMUNITY,
            'slug' => 'open-meeting-page',
            'admin' => 'no',
        ]);

        Meeting::factory()->create([
            'slug' => 'open-meeting',
            'page_id' => $publicPage->id,
        ]);

        $this->get('/community/open-meeting')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // 3. Page-less meetings are publicly accessible (no admin restriction)
    // -------------------------------------------------------------------------

    #[Test]
    public function meeting_without_page_is_publicly_accessible(): void
    {
        Meeting::factory()->create([
            'slug' => 'page-less-meeting',
            'page_id' => null,
        ]);

        $this->get('/community/page-less-meeting')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // 4. Public navigation never exposes admin-only pages
    // -------------------------------------------------------------------------

    #[Test]
    public function navigation_header_does_not_expose_admin_only_pages(): void
    {
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'secret-admin-nav-page',
            'heading' => 'Secret Admin Page',
            'navigation' => true,
            'admin' => 'yes',
        ]);

        Cache::forget('nav_pages');

        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertDontSee('Secret Admin Page');
        $response->assertDontSee('/church/secret-admin-nav-page');
    }

    #[Test]
    public function navigation_header_shows_public_navigation_pages(): void
    {
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'visible-nav-page',
            'heading' => 'Visible Nav Page',
            'navigation' => true,
            'admin' => 'no',
        ]);

        Cache::forget('nav_pages');

        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Visible Nav Page');
    }

    #[Test]
    public function sitemap_does_not_include_meetings_linked_to_admin_pages(): void
    {
        $adminPage = Page::factory()->create([
            'area' => PageArea::COMMUNITY,
            'slug' => 'restricted-community-page',
            'admin' => 'yes',
        ]);

        Meeting::factory()->create([
            'slug' => 'admin-backed-meeting',
            'page_id' => $adminPage->id,
        ]);

        $this->forgetGeneratedSitemap();

        $sitemapResponse = $this->get('/sitemap.xml');
        $this->assertStringNotContainsString(
            '/community/admin-backed-meeting',
            (string) $sitemapResponse->getContent()
        );
    }

    #[Test]
    public function sitemap_does_not_include_members_area_pages(): void
    {
        Page::factory()->create([
            'area' => PageArea::MEMBERS,
            'slug' => 'members-only-page',
            'admin' => 'no',
        ]);

        $this->forgetGeneratedSitemap();

        $sitemapResponse = $this->get('/sitemap.xml');

        $this->assertStringNotContainsString(
            '/members/members-only-page',
            (string) $sitemapResponse->getContent()
        );
    }

    #[Test]
    public function sitemap_does_not_include_meetings_linked_to_members_pages(): void
    {
        $membersPage = Page::factory()->create([
            'area' => PageArea::MEMBERS,
            'slug' => 'members-meeting-page',
            'admin' => 'no',
        ]);

        Meeting::factory()->create([
            'slug' => 'members-backed-meeting',
            'page_id' => $membersPage->id,
        ]);

        $this->forgetGeneratedSitemap();

        $sitemapResponse = $this->get('/sitemap.xml');

        $this->assertStringNotContainsString(
            '/community/members-backed-meeting',
            (string) $sitemapResponse->getContent()
        );
    }

    // -------------------------------------------------------------------------
    // 5. Canonical URL, sitemap, and HTML all agree on the same sermon URL shape
    // -------------------------------------------------------------------------

    #[Test]
    public function sermon_html_canonical_matches_sitemap_url(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-canonical-sermon',
            'date' => '2024-03-10',
        ]);

        $canonicalUrl = app(SermonExposurePolicy::class)->canonicalUrl($sermon);

        $response = $this->get($canonicalUrl);
        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical" href="'.$canonicalUrl.'">', false);

        $this->forgetGeneratedSitemap();

        $sitemapContent = (string) $this->get('/sitemap.xml')->getContent();
        $this->assertStringContainsString(
            '<loc>'.rtrim($canonicalUrl, '/').'</loc>',
            $sitemapContent
        );
    }

    #[Test]
    public function slug_only_sermon_route_redirects_to_canonical_date_url(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'redirect-sermon',
            'date' => '2024-06-15',
        ]);

        $slugRoute = route('showSermon', $sermon->slug);
        $canonicalUrl = app(SermonExposurePolicy::class)->canonicalUrl($sermon);

        $this->get($slugRoute)
            ->assertRedirect($canonicalUrl)
            ->assertStatus(301);
    }

    private function forgetGeneratedSitemap(): void
    {
        Cache::forget('sitemap');
        File::delete(app(SitemapService::class)->getFilePath());
    }
}
