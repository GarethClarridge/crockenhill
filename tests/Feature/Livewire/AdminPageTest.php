<?php

namespace Tests\Feature\Livewire;

use App\Enums\PageArea;
use App\Livewire\Admin\Pages\CreatePage;
use App\Livewire\Admin\Pages\EditPage;
use App\Livewire\Admin\Pages\ListPages;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function it_renders_successfully(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListPages::class)
            ->assertStatus(200)
            ->assertSee('Pages');
    }

    #[Test]
    public function it_can_search_pages(): void
    {
        $this->actingAs($this->admin);

        Page::factory()->create(['heading' => 'About Us', 'description' => 'Info about church']);
        Page::factory()->create(['heading' => 'Contact', 'description' => 'How to reach us']);

        Livewire::test(ListPages::class)
            ->set('search', 'About')
            ->assertSee('About Us')
            ->assertDontSee('Contact');
    }

    #[Test]
    public function it_can_filter_by_area(): void
    {
        $this->actingAs($this->admin);

        Page::factory()->create(['heading' => 'Page A', 'area' => 'community']);
        Page::factory()->create(['heading' => 'Page B', 'area' => 'members']);

        Livewire::test(ListPages::class)
            ->set('areaFilter', 'community')
            ->assertSee('Page A')
            ->assertDontSee('Page B');
    }

    #[Test]
    public function it_can_delete_a_page(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        Livewire::test(ListPages::class)
            ->call('delete', $page)
            ->assertDispatched('notify');

        $this->assertModelMissing($page);
    }

    #[Test]
    public function it_can_delete_multiple_pages(): void
    {
        $this->actingAs($this->admin);

        $page1 = Page::factory()->create();
        $page2 = Page::factory()->create();
        $page3 = Page::factory()->create();

        Livewire::test(ListPages::class)
            ->set('selected', [$page1->id, $page2->id])
            ->call('deleteSelected')
            ->assertDispatched('notify');

        $this->assertModelMissing($page1);
        $this->assertModelMissing($page2);
        $this->assertModelExists($page3);
    }

    #[Test]
    public function it_can_sort_pages(): void
    {
        $this->actingAs($this->admin);

        Page::factory()->create(['heading' => 'Alpha']);
        Page::factory()->create(['heading' => 'Omega']);

        Livewire::test(ListPages::class)
            ->set('sortBy', 'heading')
            ->set('sortDirection', 'asc')
            ->call('sort', 'heading')
            ->assertSet('sortDirection', 'desc');
    }

    #[Test]
    public function it_resets_invalid_sort_input_to_safe_defaults(): void
    {
        $this->actingAs($this->admin);

        Page::factory()->create(['heading' => 'Alpha']);

        Livewire::test(ListPages::class)
            ->set('sortBy', 'invalid_column')
            ->set('sortDirection', 'sideways')
            ->assertSet('sortBy', 'updated_at')
            ->assertSet('sortDirection', 'desc')
            ->assertSee('Alpha');
    }

    #[Test]
    public function admin_can_create_page_and_convert_markdown_to_safe_html(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePage::class)
            ->set('heading', 'About Crockenhill')
            ->set('description', 'Information about our church and mission.')
            ->set('area', PageArea::CHURCH->value)
            ->set('admin', true)
            ->set('navigation', true)
            ->set('markdown', '# Welcome to Crockenhill')
            ->call('save')
            ->assertRedirect(route('admin.pages.index'));

        $page = Page::query()->where('slug', 'about-crockenhill')->firstOrFail();

        $this->assertSame('About Crockenhill', $page->heading);
        $this->assertSame(PageArea::CHURCH, $page->area);
        $this->assertSame('yes', $page->admin);
        $this->assertTrue($page->navigation);
        $this->assertStringContainsString('<h1>Welcome to Crockenhill</h1>', $page->body);
    }

    #[Test]
    public function create_page_preserves_manual_slug_when_heading_changes(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePage::class)
            ->set('slug', 'custom-manual-slug')
            ->set('heading', 'Brand New Heading')
            ->assertSet('slug', 'custom-manual-slug');
    }

    #[Test]
    public function create_page_validates_required_fields(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePage::class)
            ->set('heading', '')
            ->set('slug', '')
            ->set('description', '')
            ->call('save')
            ->assertHasErrors(['heading', 'slug', 'description']);
    }

    #[Test]
    public function edit_page_mounts_existing_values(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create([
            'heading' => 'Existing Page',
            'slug' => 'existing-page',
            'area' => PageArea::COMMUNITY->value,
            'admin' => 'yes',
            'navigation' => true,
            'description' => 'Existing page description.',
            'markdown' => 'Existing markdown',
        ]);

        Livewire::test(EditPage::class, ['page' => $page])
            ->assertSet('heading', 'Existing Page')
            ->assertSet('slug', 'existing-page')
            ->assertSet('area', PageArea::COMMUNITY->value)
            ->assertSet('admin', true)
            ->assertSet('navigation', true)
            ->assertSet('description', 'Existing page description.')
            ->assertSet('markdown', 'Existing markdown');
    }

    #[Test]
    public function admin_can_update_page_from_edit_component(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create([
            'heading' => 'Old Heading',
            'slug' => 'old-heading',
            'area' => PageArea::CHURCH->value,
            'admin' => 'no',
            'navigation' => false,
            'description' => 'Old description.',
            'markdown' => '# Old Markdown',
        ]);

        Livewire::test(EditPage::class, ['page' => $page])
            ->set('heading', 'Updated Heading')
            ->set('slug', 'updated-heading')
            ->set('area', PageArea::MEMBERS->value)
            ->set('admin', true)
            ->set('navigation', true)
            ->set('description', 'Updated page description.')
            ->set('markdown', '## Updated Markdown')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Page updated');

        $page->refresh();

        $this->assertSame('Updated Heading', $page->heading);
        $this->assertSame('updated-heading', $page->slug);
        $this->assertSame(PageArea::MEMBERS, $page->area);
        $this->assertSame('yes', $page->admin);
        $this->assertTrue($page->navigation);
        $this->assertSame('Updated page description.', $page->description);
        $this->assertStringContainsString('<h2>Updated Markdown</h2>', $page->body);
    }

    #[Test]
    public function it_shows_whether_pages_are_admin_only_in_the_listing(): void
    {
        $this->actingAs($this->admin);

        Page::factory()->create([
            'heading' => 'Admin Page',
            'admin' => 'yes',
        ]);

        Page::factory()->create([
            'heading' => 'Public Page',
            'admin' => 'no',
        ]);

        Livewire::test(ListPages::class)
            ->assertSee('Visibility')
            ->assertSee('Admin only')
            ->assertSee('Public');
    }

    #[Test]
    public function edit_page_validates_slug_uniqueness(): void
    {
        $this->actingAs($this->admin);

        $existing = Page::factory()->create(['slug' => 'existing-slug', 'area' => PageArea::CHURCH->value]);
        $editable = Page::factory()->create(['slug' => 'editable-slug', 'area' => PageArea::CHURCH->value]);

        Livewire::test(EditPage::class, ['page' => $editable])
            ->set('slug', $existing->slug)
            ->call('save')
            ->assertHasErrors(['slug' => ['unique']]);
    }
}
