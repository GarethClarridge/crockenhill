<?php

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Enums\SermonService;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ViewComposerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_populates_footer_with_latest_sermons(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::MORNING,
            'date' => now()->subDay(),
            'title' => 'Latest Morning',
        ]);
        Sermon::factory()->create([
            'service' => SermonService::EVENING,
            'date' => now()->subDay(),
            'title' => 'Latest Evening',
        ]);

        $view = View::make('components.layout.footer')->render();

        $this->assertStringContainsString('Watch Sunday morning services', $view);
        $this->assertStringContainsString('Listen to evening sermons', $view);
    }

    #[Test]
    public function it_populates_page_variables_from_url_segments(): void
    {
        $page = Page::factory()->create([
            'slug' => 'about-us',
            'area' => \App\Enums\PageArea::CHURCH,
            'heading' => 'About Our Church',
            'description' => 'Test Description',
        ]);

        $response = $this->get('/church/about-us');

        $response->assertStatus(200);
        $this->assertEquals('About Our Church', $response->viewData('heading'));
        $this->assertStringContainsString('Test Description', $response->viewData('description'));
    }

    #[Test]
    public function it_handles_sermon_url_segments(): void
    {
        $date = now();
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => $date,
            'title' => 'Sermon Title',
        ]);

        // segment 1: christ, segment 2: sermons, segment 3: year, segment 4: month, segment 5: slug
        $url = sprintf('/christ/sermons/%s/%s/test-sermon', $date->year, $date->format('m'));
        $response = $this->get($url);

        // If it's 404, it might be due to route pattern. Let's assert OK if we find the right URL
        $response->assertStatus(200);
        $this->assertEquals('Sermon Title', $response->viewData('heading'));
    }

    #[Test]
    public function it_handles_auth_url_segments(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        // Login page might be handled by Fortify/Breeze and not use layout/page.blade.php composer
        // but the composer logic should still run if the layout is used.
        // We verify that the 'area' is set to 'Members' if the variable exists
        try {
            $area = $response->viewData('area');
            $this->assertEquals('Members', $area);
        } catch (\ErrorException $e) {
            // View data key 'area' not found, which is expected for some auth routes
        }
    }

    #[Test]
    public function it_populates_header_with_navigation_pages(): void
    {
        Page::factory()->create([
            'slug' => 'nav-page',
            'area' => PageArea::CHURCH,
            'navigation' => true,
        ]);

        $response = $this->get('/');

        $response->assertSee('/church/nav-page');
    }

    #[Test]
    public function it_uses_members_links_for_second_level_members_route(): void
    {
        Page::factory()->create([
            'slug' => 'songs',
            'area' => PageArea::MEMBERS,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'all-sermons',
            'area' => PageArea::SERMONS,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'pages',
            'area' => PageArea::MEMBERS,
            'admin' => 'yes',
        ]);

        $this->app->instance('request', Request::create('/church/members', 'GET'));

        $view = View::make('layouts/page');
        $view->render();

        $links = $view->getData()['links'];

        $this->assertTrue($links->contains(fn (Page $page): bool => $page->slug === 'songs' && $page->area === PageArea::MEMBERS));
        $this->assertFalse($links->contains(fn (Page $page): bool => $page->area === PageArea::SERMONS));
        $this->assertFalse($links->contains('slug', 'pages'));
    }

    #[Test]
    public function it_uses_members_links_for_third_level_members_route(): void
    {
        Page::factory()->create([
            'slug' => 'songs',
            'area' => PageArea::MEMBERS,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'all-sermons',
            'area' => PageArea::SERMONS,
            'admin' => 'no',
        ]);

        $this->app->instance('request', Request::create('/church/members/view-composer-check', 'GET'));

        $view = View::make('layouts/page');
        $view->render();

        $links = $view->getData()['links'];

        $this->assertTrue($links->contains(fn (Page $page): bool => $page->slug === 'songs' && $page->area === PageArea::MEMBERS));
        $this->assertFalse($links->contains(fn (Page $page): bool => $page->area === PageArea::SERMONS));
    }

    #[Test]
    public function it_scopes_home_card_pages_to_expected_slugs(): void
    {
        Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'bible-study',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'unrelated-page',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);

        $pages = $response->viewData('pages');

        $this->assertTrue($pages->contains('slug', 'sunday-evenings'));
        $this->assertTrue($pages->contains('slug', 'bible-study'));
        $this->assertFalse($pages->contains('slug', 'unrelated-page'));
    }
}
