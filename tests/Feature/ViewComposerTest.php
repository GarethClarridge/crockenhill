<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Enums\SermonService;
use App\Models\Page;
use App\Models\Sermon;
use App\Models\User;
use App\Presenters\RelatedPagePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ViewComposerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_the_footer_sermon_links(): void
    {
        Sermon::factory()->create([
            'service' => SermonService::Morning,
            'date' => now()->subDay(),
            'title' => 'Latest Morning',
        ]);
        Sermon::factory()->create([
            'service' => SermonService::Evening,
            'date' => now()->subDay(),
            'title' => 'Latest Evening',
        ]);

        $view = View::make('components.layout.footer')->render();

        $this->assertStringContainsString('Watch Sunday morning services', $view);
        $this->assertStringContainsString('Listen to evening sermons', $view);
    }

    #[Test]
    public function it_populates_page_variables_from_controller_data(): void
    {
        $page = Page::factory()->create([
            'slug' => 'about-us',
            'area' => PageArea::Church,
            'heading' => 'About Our Church',
            'description' => 'Test Description',
        ]);

        $response = $this->get('/church/about-us');

        $response->assertStatus(200);
        $this->assertEquals('About Our Church', $response->viewData('heading'));
        $this->assertStringContainsString('Test Description', $response->viewData('description'));
    }

    #[Test]
    public function it_renders_sermon_layout_from_controller_data(): void
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
    public function it_keeps_explicit_data_when_rendering_the_page_show_view(): void
    {
        $view = View::make('pages.show', [
            'page' => null,
            'heading' => 'Explicit heading',
            'description' => 'Explicit description',
            'content' => '<p>Explicit content</p>',
            'area' => 'church',
            'slug' => 'explicit-page',
            'links' => collect(),
        ]);

        $view->render();

        $this->assertSame('Explicit heading', $view->getData()['heading']);
        $this->assertSame('Explicit description', $view->getData()['description']);
        $this->assertSame('church', $view->getData()['area']);
        $this->assertCount(0, $view->getData()['links']);
    }

    #[Test]
    public function it_populates_header_with_navigation_pages(): void
    {
        Page::factory()->create([
            'slug' => 'nav-page',
            'area' => PageArea::Church,
            'navigation' => true,
        ]);

        $response = $this->get('/');

        $response->assertSee('/church/nav-page');
    }

    #[Test]
    public function it_renders_accessible_expanded_navigation_styling(): void
    {
        Page::factory()->create([
            'slug' => 'expanded-nav-page',
            'area' => PageArea::Church,
            'navigation' => true,
        ]);

        $response = $this->get('/');

        $response->assertOk();

        $content = (string) $response->getContent();

        $this->assertStringContainsString('id="mobile-menu"', $content);
        $this->assertStringContainsString('bg-gradient-to-bl', $content);
        $this->assertStringContainsString('from-cbc-teal-deeper/95', $content);
        $this->assertStringContainsString('via-cbc-teal-dark/95', $content);
        $this->assertStringContainsString('to-cbc-teal/92', $content);
        $this->assertStringContainsString(":class=\"expanded ? 'lg:pointer-events-none lg:opacity-0' : 'lg:pointer-events-auto lg:opacity-100'\"", $content);
        $this->assertStringContainsString('absolute inset-y-0 left-16 right-16 z-10 hidden items-center justify-center text-center font-display text-xl opacity-0 transition-all duration-200 active:scale-95', $content);
        $this->assertStringContainsString(":class=\"expanded ? 'pointer-events-auto opacity-100' : 'pointer-events-none opacity-0'\"", $content);
        $this->assertStringContainsString(':aria-hidden="!expanded"', $content);
        $this->assertStringContainsString(':inert="!expanded"', $content);
        $this->assertStringContainsString(":class=\"expanded ? 'pointer-events-none opacity-0' : 'pointer-events-auto opacity-100'\"", $content);
        $this->assertStringContainsString(':aria-hidden="expanded"', $content);
        $this->assertStringContainsString(':inert="expanded"', $content);
        $this->assertStringContainsString('top-full z-30 -mt-px', $content);
        $this->assertStringContainsString('font-sans normal-case text-base leading-relaxed text-white shadow-2xl', $content);
        $this->assertStringContainsString('x-transition:enter="transform-gpu transition ease-out duration-200"', $content);
        $this->assertStringContainsString('x-transition:enter-start="-translate-y-3 opacity-0"', $content);
        $this->assertStringContainsString('x-transition:enter-end="translate-y-0 opacity-100"', $content);
        $this->assertStringContainsString('x-transition:leave-end="-translate-y-2 opacity-0"', $content);
        $this->assertStringContainsString('border-b border-white/15 pb-4', $content);
        $this->assertStringContainsString('font-display text-lg font-normal no-underline transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark', $content);
        $this->assertStringContainsString('inline-flex rounded-md px-3 py-1.5 text-base no-underline transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark', $content);
        $this->assertStringContainsString('focus-visible:ring-2 focus-visible:ring-white/90 focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark', $content);
        $this->assertStringNotContainsString('focus:ring-2 focus:ring-white transition-all duration-200', $content);
        $this->assertStringNotContainsString('rounded-md bg-gradient-to-r from-teal-600 to-teal-800', $content);
    }

    #[Test]
    public function it_populates_header_with_members_link_for_authenticated_users(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('/church/members');
    }

    #[Test]
    public function it_resolves_members_links_explicitly_without_route_inference(): void
    {
        $membersSlug = 'view-composer-members-link';

        Page::factory()->create([
            'slug' => $membersSlug,
            'area' => PageArea::Members,
            'admin' => 'no',
        ]);

        Page::unguarded(fn () => Page::query()->updateOrCreate(
            ['slug' => 'all-sermons'],
            ['area' => PageArea::Sermons, 'admin' => 'no', 'heading' => 'All Sermons', 'description' => 'All sermons', 'body' => 'All sermons', 'markdown' => '', 'navigation' => false],
        ));

        Page::unguarded(fn () => Page::query()->updateOrCreate(
            ['slug' => 'pages'],
            ['area' => PageArea::Members, 'admin' => 'yes', 'heading' => 'Pages', 'description' => 'Admin pages', 'body' => 'Pages', 'markdown' => '', 'navigation' => false],
        ));

        $links = app(RelatedPagePresenter::class)->ordered(
            linkArea: PageArea::Members->value,
            slugToExclude: 'members',
            secondSlugToExclude: 'members',
            excludeAdminPages: true,
        );

        $this->assertTrue($links->contains(fn (array $link): bool => $link['slug'] === $membersSlug && $link['area'] === PageArea::Members->value));
        $this->assertFalse($links->contains(fn (array $link): bool => $link['area'] === PageArea::Sermons->value));
        $this->assertFalse($links->contains(fn (array $link): bool => $link['slug'] === 'pages'));
    }

    #[Test]
    public function it_scopes_home_card_pages_to_expected_slugs(): void
    {
        Page::query()->updateOrCreate(
            ['slug' => 'sunday-evenings'],
            Page::factory()->raw([
                'slug' => 'sunday-evenings',
                'area' => PageArea::Community,
                'admin' => 'no',
            ]),
        );

        Page::query()->updateOrCreate(
            ['slug' => 'bible-study'],
            Page::factory()->raw([
                'slug' => 'bible-study',
                'area' => PageArea::Community,
                'admin' => 'no',
            ]),
        );

        Page::factory()->create([
            'slug' => 'unrelated-page',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);

        $pages = $response->viewData('pages');

        $this->assertTrue($pages->contains('slug', 'sunday-evenings'));
        $this->assertTrue($pages->contains('slug', 'bible-study'));
        $this->assertFalse($pages->contains('slug', 'unrelated-page'));
    }

    #[Test]
    public function it_renders_page_card_with_the_shared_feature_cta_button(): void
    {
        $page = Page::factory()->create([
            'slug' => 'view-composer-sunday-evenings-card',
            'heading' => 'Sunday Evenings',
            'description' => 'An evening service.',
            'area' => PageArea::Community,
        ]);

        $view = View::make('components.page-card', [
            'page' => $page,
        ])->render();

        $this->assertStringContainsString('Learn about Sunday Evenings', $view);
        $this->assertStringContainsString('bg-[linear-gradient(120deg,var(--color-cbc-teal)_0%,var(--color-cbc-teal-dark)_55%,#0e3a3c_100%)]', $view);
        $this->assertStringContainsString('justify-between', $view);
    }

    #[Test]
    public function it_renders_sermon_list_in_a_centered_grid_with_feature_cta(): void
    {
        $sermon = new Sermon([
            'title' => 'Rendered Sermon',
            'slug' => 'rendered-sermon',
            'date' => now(),
            'service' => SermonService::Morning,
        ]);

        $view = View::make('components.sermon-list', [
            'sermons' => collect([$sermon]),
            'groupedByDate' => false,
        ])->render();

        $this->assertStringContainsString('[grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),19rem))]', $view);
        $this->assertStringContainsString('bg-[linear-gradient(120deg,var(--color-cbc-teal)_0%,var(--color-cbc-teal-dark)_55%,#0e3a3c_100%)]', $view);
        $this->assertStringContainsString('View Sermon', $view);
    }

    #[Test]
    public function it_renders_related_pages_in_a_centered_grid_with_footer_spacing(): void
    {
        $links = Page::factory()->count(3)->create([
            'area' => PageArea::Church,
        ]);

        $view = View::make('components.related-pages', [
            'links' => $links,
        ])->render();

        $this->assertStringContainsString('max-w-6xl', $view);
        $this->assertStringContainsString('gap-6', $view);
        $this->assertStringContainsString('[grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),19rem))]', $view);
    }

    #[Test]
    public function it_renders_public_cta_with_gradient_border_and_centered_button(): void
    {
        $view = View::make('components.public-cta', [
            'link' => '/christ/sermons',
            'label' => 'Browse sermons',
        ])->render();

        $this->assertStringContainsString('Browse sermons', $view);
        $this->assertStringContainsString('shadow-[0_10px_24px_rgba(20,85,87,0.18)]', $view);
        $this->assertStringContainsString('rounded-[11px]', $view);
    }

    #[Test]
    public function homepage_uses_shared_public_cta_component_labels(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('What to expect on Sunday mornings');
        $response->assertSee('Explore the good news about Jesus');
        $response->assertSee('shadow-[0_10px_24px_rgba(20,85,87,0.18)]', false);
    }
}
