<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Page::query()
            ->whereIn('slug', ['sermons', 'standard-page', 'free-bible', 'some-page', 'admin-only'])
            ->delete();
    }

    #[Test]
    public function show_page_returns_200_for_valid_area_with_landing_page(): void
    {
        // Use 'sermons' because the section landing routes intentionally take precedence over CMS pages.
        // /sermons is handled dynamically by PageController::showPage.
        Page::factory()->create([
            'area' => PageArea::Sermons,
            'slug' => 'sermons',
        ]);

        $response = $this->get('/sermons');

        $response->assertStatus(200);
    }

    #[Test]
    public function show_page_returns_404_for_invalid_area(): void
    {
        // Areas like 'foo' are not in PageArea enum, so PageController::showPage should abort(404)
        $response = $this->get('/foo');

        $response->assertStatus(404);
    }

    #[Test]
    public function show_page_redirects_unauthenticated_guest_for_members_area_landing_page(): void
    {
        // No landing page exists
        $response = $this->get('/members');

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function show_page_returns_404_when_landing_page_is_missing_for_non_members_area(): void
    {
        // Both christ and community have explicit landing routes in web.php.
        // Let's use 'sermons' area which only has dynamic PageController route for /sermons
        Page::query()->where('area', PageArea::Sermons)->where('slug', 'sermons')->delete();

        $response = $this->get('/sermons');

        $response->assertStatus(404);
    }

    #[Test]
    public function show_resolves_default_view_for_standard_page(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::Christ,
            'slug' => 'standard-page',
        ]);

        $response = $this->get('/christ/standard-page');

        $response->assertStatus(200);
        $response->assertViewIs('pages.show');
    }

    #[Test]
    public function show_resolves_custom_override_view_when_it_exists(): void
    {
        // pages.christ.free-bible exists in resources/views/pages/christ/free-bible.blade.php
        $page = Page::factory()->create([
            'area' => PageArea::Christ,
            'slug' => 'free-bible',
        ]);

        $response = $this->get('/christ/free-bible');

        $response->assertStatus(200);
        $response->assertViewIs('pages.christ.free-bible');
    }

    #[Test]
    public function show_returns_404_for_non_existent_page(): void
    {
        $response = $this->get('/christ/non-existent');

        $response->assertStatus(404);
    }

    #[Test]
    public function show_returns_404_when_area_and_slug_do_not_match(): void
    {
        Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'some-page',
        ]);

        $response = $this->get('/christ/some-page');

        $response->assertStatus(404);
    }

    #[Test]
    public function show_respects_visibility_guard(): void
    {
        $page = Page::factory()->admin()->create([
            'area' => PageArea::Church,
            'slug' => 'admin-only',
        ]);

        $response = $this->get('/church/admin-only');

        // PublicPageVisibilityGuard::enforce returns a 403 (ForbiddenResponse) for admin pages if guest
        $response->assertStatus(403);
    }
}
