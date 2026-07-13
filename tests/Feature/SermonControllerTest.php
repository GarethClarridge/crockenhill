<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\Sermon;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $initialOutputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialOutputBufferLevel = ob_get_level();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialOutputBufferLevel) {
            ob_end_clean();
        }

        parent::tearDown();
    }

    // ── index ──────────────────────────────────────────────────────────────

    #[Test]
    public function sermon_index_returns_200(): void
    {
        $response = $this->get('/christ/sermons');
        $response->assertStatus(200);
    }

    #[Test]
    public function sermon_index_shows_archive_sermons(): void
    {
        Sermon::factory()->create([
            'title' => 'Grace Alone',
            'date' => now(),
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons');
        $response->assertStatus(200);
        $response->assertSee('Grace Alone');
    }

    // ── all ────────────────────────────────────────────────────────────────

    #[Test]
    public function sermon_all_redirects_to_the_canonical_archive(): void
    {
        $response = $this->get('/christ/sermons/all');
        $response->assertRedirect('/christ/sermons');
        $response->assertStatus(301);
    }

    #[Test]
    public function sermon_all_redirect_preserves_the_query_string(): void
    {
        $response = $this->get('/christ/sermons/all?book=John&chapter=3&page=2');
        $response->assertRedirect('/christ/sermons?book=John&chapter=3&page=2');
        $response->assertStatus(301);
    }

    // ── showDated ─────────────────────────────────────────────────────────

    #[Test]
    public function dated_sermon_route_renders_sermon(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'faith-in-action',
            'date' => Carbon::parse('2024-03-10'),
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons/2024/03/faith-in-action');
        $response->assertStatus(200);
        $response->assertSee($sermon->title);
    }

    #[Test]
    public function dated_sermon_route_returns_404_for_wrong_year(): void
    {
        Sermon::factory()->create([
            'slug' => 'wrong-year-sermon',
            'date' => Carbon::parse('2024-03-10'),
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons/2023/03/wrong-year-sermon');
        $response->assertStatus(404);
    }

    #[Test]
    public function dated_sermon_route_returns_404_for_wrong_month(): void
    {
        Sermon::factory()->create([
            'slug' => 'wrong-month-sermon',
            'date' => Carbon::parse('2024-03-10'),
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons/2024/05/wrong-month-sermon');
        $response->assertStatus(404);
    }

    #[Test]
    public function dated_route_returns_404_for_nonexistent_slug(): void
    {
        $response = $this->get('/christ/sermons/2024/03/no-such-sermon');
        $response->assertStatus(404);
    }

    // ── show (slug-only legacy redirect) ─────────────────────────────────

    #[Test]
    public function slug_only_route_redirects_to_canonical_dated_url(): void
    {
        Sermon::factory()->create([
            'slug' => 'redirect-me',
            'date' => Carbon::parse('2024-06-01'),
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons/redirect-me');
        $response->assertRedirect('/christ/sermons/2024/06/redirect-me');
    }

    #[Test]
    public function slug_only_route_returns_404_for_non_public_childrens_talk(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        Sermon::factory()->create([
            'slug' => 'hidden-childrens-talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->get('/christ/sermons/hidden-childrens-talk');
        $response->assertStatus(404);
    }

    // ── preachers ──────────────────────────────────────────────────────────

    #[Test]
    public function preachers_index_returns_200(): void
    {
        $response = $this->get('/christ/sermons/preachers');
        $response->assertStatus(200);
    }

    #[Test]
    public function preacher_page_shows_sermons_for_that_preacher(): void
    {
        $preacher = Preacher::query()->firstOrCreate(
            ['slug' => 'john-smith-feature-test'],
            ['name' => 'John Smith Feature Test', 'is_active' => true]
        );
        Sermon::factory()->create([
            'title' => 'Sermon By John',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons/preachers/john-smith-feature-test');
        $response->assertStatus(200);
        $response->assertSee('John Smith Feature Test');
    }

    #[Test]
    public function preacher_page_returns_404_for_unknown_slug(): void
    {
        $response = $this->get('/christ/sermons/preachers/nobody-here');
        $response->assertStatus(404);
    }

    // ── series ────────────────────────────────────────────────────────────

    #[Test]
    public function series_index_returns_200(): void
    {
        $response = $this->get('/christ/sermons/series');
        $response->assertStatus(200);
    }

    #[Test]
    public function series_show_lists_sermons_in_that_series(): void
    {
        Sermon::factory()->create([
            'series' => 'Life Of David',
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons/series/life-of-david');
        $response->assertStatus(200);
        $response->assertSee('Life Of David');
    }

    // ── service ────────────────────────────────────────────────────────────

    #[Test]
    public function service_morning_returns_200(): void
    {
        $response = $this->get('/christ/sermons/morning');
        $response->assertStatus(200);
        $response->assertSee('Sunday Morning');
    }

    #[Test]
    public function service_evening_returns_200(): void
    {
        $response = $this->get('/christ/sermons/evening');
        $response->assertStatus(200);
        $response->assertSee('Sunday Evening');
    }
}
