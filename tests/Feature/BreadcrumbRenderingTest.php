<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Presenters\BreadcrumbPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BreadcrumbRenderingTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // BreadcrumbPresenter unit coverage
    // -------------------------------------------------------------------------

    #[Test]
    public function presenter_builds_two_item_trail_for_area_root_page(): void
    {
        $this->get('/church');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('church', 'Church');

        $this->assertCount(2, $items);
        $this->assertSame('Home', $items[0]['name']);
        $this->assertSame('Church', $items[1]['name']);
    }

    #[Test]
    public function presenter_appends_heading_when_not_already_current_url(): void
    {
        $this->get('/church/about');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('church', 'About');

        $last = end($items);
        $this->assertSame('About', $last['name']);
    }

    #[Test]
    public function presenter_builds_sermons_trail_for_sermon_show_page(): void
    {
        $this->get('/christ/sermons/2024/01/test-sermon');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('christ', 'Test Sermon');

        $names = array_column($items, 'name');
        $this->assertContains('Christ', $names);
        $this->assertContains('Sermons', $names);
        $this->assertContains('Test Sermon', $names);
    }

    #[Test]
    public function presenter_builds_sermons_trail_for_preacher_page(): void
    {
        $this->get('/christ/sermons/preachers/john-smith');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('christ', 'John Smith');

        $names = array_column($items, 'name');
        $this->assertContains('Christ', $names);
        $this->assertContains('Sermons', $names);
        $this->assertContains('Preachers', $names);
        $this->assertContains('John Smith', $names);
    }

    #[Test]
    public function presenter_builds_sermons_trail_for_series_page(): void
    {
        $this->get('/christ/sermons/series/the-gospel-of-mark');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('christ', 'The Gospel of Mark');

        $names = array_column($items, 'name');
        $this->assertContains('Christ', $names);
        $this->assertContains('Sermons', $names);
        $this->assertContains('Series', $names);
        $this->assertContains('The Gospel of Mark', $names);
    }

    #[Test]
    public function presenter_builds_sermons_trail_for_service_page(): void
    {
        $this->get('/christ/sermons/morning');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('christ', 'Morning Services');

        $names = array_column($items, 'name');
        $this->assertContains('Christ', $names);
        $this->assertContains('Sermons', $names);
        $this->assertContains('Morning Services', $names);
    }

    #[Test]
    public function presenter_builds_songs_trail_for_song_detail_page(): void
    {
        $this->get('/church/songs/amazing-grace');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('church', 'Amazing Grace');

        $names = array_column($items, 'name');
        $this->assertContains('Church', $names);
        $this->assertContains('Songs', $names);
        $this->assertContains('Amazing Grace', $names);
    }

    #[Test]
    public function presenter_builds_meeting_events_trail_for_meeting_events_page(): void
    {
        $this->get('/meetings/buzz-club/events');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('community', 'Buzz Club - All Events');

        $names = array_column($items, 'name');
        $this->assertContains('Community', $names);
        $this->assertContains('Buzz Club', $names);
        $this->assertContains('Buzz Club - All Events', $names);
    }

    #[Test]
    public function presenter_builds_admin_trail_for_admin_subsection(): void
    {
        $this->get('/admin/sermons/edit');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('', 'Edit Sermon');

        $names = array_column($items, 'name');
        $this->assertContains('Members', $names);
        $this->assertContains('Sermons', $names);
    }

    #[Test]
    public function presenter_json_ld_has_correct_schema_structure(): void
    {
        $this->get('/church');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('church', 'Church');
        $jsonLd = $presenter->jsonLd($items);

        $this->assertSame('https://schema.org', $jsonLd['@context']);
        $this->assertSame('BreadcrumbList', $jsonLd['@type']);
        $this->assertCount(count($items), $jsonLd['itemListElement']);

        foreach ($jsonLd['itemListElement'] as $index => $element) {
            $this->assertSame('ListItem', $element['@type']);
            $this->assertSame($index + 1, $element['position']);
            $this->assertArrayHasKey('name', $element);
            $this->assertArrayHasKey('item', $element);
        }
    }

    // -------------------------------------------------------------------------
    // Blade component regression: public pages render breadcrumbs correctly
    // -------------------------------------------------------------------------

    #[Test]
    public function breadcrumbs_component_renders_json_ld_script_tag(): void
    {
        $this->get('/church');

        $rendered = Blade::render('<x-breadcrumbs area="church" heading="Church" />');

        $this->assertStringContainsString('application/ld+json', $rendered);
        $this->assertStringContainsString('BreadcrumbList', $rendered);
    }

    #[Test]
    public function breadcrumbs_component_renders_nav_element_by_default(): void
    {
        $this->get('/church');

        $rendered = Blade::render('<x-breadcrumbs area="church" heading="Church" />');

        $this->assertStringContainsString('<nav', $rendered);
        $this->assertStringContainsString('aria-label="Breadcrumb"', $rendered);
    }

    #[Test]
    public function breadcrumbs_component_omits_nav_when_json_only(): void
    {
        $this->get('/church');

        $rendered = Blade::render('<x-breadcrumbs area="church" heading="Church" :jsonOnly="true" />');

        $this->assertStringNotContainsString('<nav', $rendered);
        $this->assertStringContainsString('application/ld+json', $rendered);
    }

    #[Test]
    public function breadcrumbs_component_does_not_contain_inline_php_data_assembly(): void
    {
        $rendered = Blade::render('<x-breadcrumbs area="church" heading="Church" />');

        // The presenter is used; no raw $breadcrumbItems construction should bleed through
        $this->assertStringNotContainsString('$breadcrumbItems = []', $rendered);
    }

    // -------------------------------------------------------------------------
    // HTTP regression: public pages serve breadcrumb JSON-LD
    // -------------------------------------------------------------------------

    #[Test]
    public function church_area_page_serves_breadcrumb_json_ld(): void
    {
        $response = $this->get('/church');

        $response->assertStatus(200);
        $response->assertSee('BreadcrumbList', false);
    }

    #[Test]
    public function sermon_index_page_serves_breadcrumb_json_ld(): void
    {
        $response = $this->get('/christ/sermons');

        $response->assertStatus(200);
        $response->assertSee('BreadcrumbList', false);
    }

    #[Test]
    public function calendar_page_serves_breadcrumb_json_ld(): void
    {
        $response = $this->get('/calendar');

        $response->assertStatus(200);
        $response->assertSee('BreadcrumbList', false);
    }

    #[Test]
    public function presenter_builds_deep_hierarchy_for_book_filtered_archive(): void
    {
        $this->get('/christ/sermons?book=Genesis');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('christ', 'Genesis | Sermons');

        $names = array_column($items, 'name');
        $this->assertContains('Home', $names);
        $this->assertContains('Christ', $names);
        $this->assertContains('Sermons', $names);
        $this->assertContains('Genesis', $names);

        $genesisItem = collect($items)->firstWhere('name', 'Genesis');
        $this->assertStringContainsString('book=Genesis', $genesisItem['item']);
    }

    #[Test]
    public function presenter_builds_deeper_hierarchy_for_book_and_chapter_filtered_archive(): void
    {
        $this->get('/christ/sermons?book=Genesis&chapter=1');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('christ', 'Genesis 1 | Sermons');

        $names = array_column($items, 'name');
        $this->assertContains('Sermons', $names);
        $this->assertContains('Genesis', $names);
        $this->assertContains('Chapter 1', $names);

        $genesisItem = collect($items)->firstWhere('name', 'Genesis');
        $this->assertStringContainsString('book=Genesis', $genesisItem['item']);
        $this->assertStringNotContainsString('chapter=1', $genesisItem['item']);

        $chapterItem = collect($items)->firstWhere('name', 'Chapter 1');
        $this->assertStringContainsString('book=Genesis', $chapterItem['item']);
        $this->assertStringContainsString('chapter=1', $chapterItem['item']);
    }

    #[Test]
    public function presenter_builds_hierarchy_for_preacher_filtered_archive(): void
    {
        // Mock a preacher for resolution
        Preacher::factory()->create(['id' => 1, 'name' => 'Mark Davies', 'slug' => 'mark-davies']);

        $this->get('/christ/sermons?preacher=1');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('christ', 'Mark Davies | Sermons');

        $names = array_column($items, 'name');
        $this->assertContains('Sermons', $names);
        $this->assertContains('Mark Davies', $names);

        $preacherItem = collect($items)->firstWhere('name', 'Mark Davies');
        $this->assertStringContainsString('preacher=1', $preacherItem['item']);
    }

    #[Test]
    public function presenter_builds_hierarchy_for_series_filtered_archive(): void
    {
        $this->get('/christ/sermons?series=The+Gospel+of+Mark');

        $presenter = app(BreadcrumbPresenter::class);
        $items = $presenter->items('christ', 'The Gospel of Mark | Sermons');

        $names = array_column($items, 'name');
        $this->assertContains('Sermons', $names);
        $this->assertContains('The Gospel of Mark', $names);

        $seriesItem = collect($items)->firstWhere('name', 'The Gospel of Mark');
        $this->assertStringContainsString('series=The', $seriesItem['item']);
        $this->assertStringContainsString('Gospel', $seriesItem['item']);
        $this->assertStringContainsString('Mark', $seriesItem['item']);
    }
}
