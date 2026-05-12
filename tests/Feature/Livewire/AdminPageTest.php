<?php

declare(strict_types=1);

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
            ->set('form.heading', 'About Crockenhill')
            ->set('form.description', 'Information about our church and mission.')
            ->set('form.area', PageArea::Church->value)
            ->set('form.admin', true)
            ->set('form.navigation', true)
            ->set('form.markdown', '# Welcome to Crockenhill')
            ->call('save')
            ->assertRedirect(route('admin.pages.index'));

        $page = Page::query()->where('slug', 'about-crockenhill')->firstOrFail();

        $this->assertSame('About Crockenhill', $page->heading);
        $this->assertSame(PageArea::Church, $page->area);
        $this->assertSame('yes', $page->admin);
        $this->assertTrue($page->navigation);
        $this->assertStringContainsString('<h1>Welcome to Crockenhill</h1>', $page->body);
    }

    #[Test]
    public function admin_create_page_defaults_to_public_visibility(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePage::class)
            ->set('form.heading', 'Welcome')
            ->set('form.description', 'Welcome page description.')
            ->set('form.area', PageArea::Church->value)
            ->set('form.navigation', false)
            ->set('form.markdown', 'Welcome page copy')
            ->call('save')
            ->assertRedirect(route('admin.pages.index'));

        $page = Page::query()->where('slug', 'welcome')->firstOrFail();

        $this->assertSame('no', $page->admin);
    }

    #[Test]
    public function create_page_preserves_manual_slug_when_heading_changes(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePage::class)
            ->set('form.slug', 'custom-manual-slug')
            ->set('form.heading', 'Brand New Heading')
            ->assertSet('form.slug', 'custom-manual-slug');
    }

    #[Test]
    public function create_page_tracks_generated_slug_until_slug_is_edited_manually(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePage::class)
            ->set('form.heading', 'First Heading')
            ->assertSet('form.slug', 'first-heading')
            ->set('form.heading', 'Second Heading')
            ->assertSet('form.slug', 'second-heading')
            ->set('form.slug', 'custom-manual-slug')
            ->set('form.heading', 'Third Heading')
            ->assertSet('form.slug', 'custom-manual-slug');
    }

    #[Test]
    public function create_page_validates_required_fields(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePage::class)
            ->set('form.heading', '')
            ->set('form.slug', '')
            ->set('form.description', '')
            ->call('save')
            ->assertHasErrors(['form.heading', 'form.slug', 'form.description']);
    }

    #[Test]
    public function edit_page_mounts_existing_values(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create([
            'heading' => 'Existing Page',
            'slug' => 'existing-page',
            'area' => PageArea::Community->value,
            'admin' => 'yes',
            'navigation' => true,
            'description' => 'Existing page description.',
            'markdown' => 'Existing markdown',
        ]);

        Livewire::test(EditPage::class, ['page' => $page])
            ->assertSet('form.heading', 'Existing Page')
            ->assertSet('form.slug', 'existing-page')
            ->assertSet('form.area', PageArea::Community->value)
            ->assertSet('form.admin', true)
            ->assertSet('form.navigation', true)
            ->assertSet('form.description', 'Existing page description.')
            ->assertSet('form.markdown', 'Existing markdown');
    }

    #[Test]
    public function admin_can_update_page_from_edit_component(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create([
            'heading' => 'Old Heading',
            'slug' => 'old-heading',
            'area' => PageArea::Church->value,
            'admin' => 'no',
            'navigation' => false,
            'description' => 'Old description.',
            'markdown' => '# Old Markdown',
        ]);

        Livewire::test(EditPage::class, ['page' => $page])
            ->set('form.heading', 'Updated Heading')
            ->set('form.slug', 'updated-heading')
            ->set('form.area', PageArea::Members->value)
            ->set('form.admin', true)
            ->set('form.navigation', true)
            ->set('form.description', 'Updated page description.')
            ->set('form.markdown', '## Updated Markdown')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Page updated');

        $page->refresh();

        $this->assertSame('Updated Heading', $page->heading);
        $this->assertSame('updated-heading', $page->slug);
        $this->assertSame(PageArea::Members, $page->area);
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

        $existing = Page::factory()->create(['slug' => 'existing-slug', 'area' => PageArea::Church->value]);
        $editable = Page::factory()->create(['slug' => 'editable-slug', 'area' => PageArea::Church->value]);

        Livewire::test(EditPage::class, ['page' => $editable])
            ->set('form.slug', $existing->slug)
            ->call('save')
            ->assertHasErrors(['form.slug' => ['unique']]);
    }
}
