<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PageResource;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PageResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->crockenhillAdmin()->create();
    }

    public function test_can_render_page_list(): void
    {
        $this->actingAs($this->admin);

        $this->get(PageResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_can_render_create_page(): void
    {
        $this->actingAs($this->admin);

        $this->get(PageResource::getUrl('create'))
            ->assertSuccessful();
    }

    public function test_can_create_page(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(PageResource\Pages\CreatePage::class)
            ->fillForm([
                'heading' => 'Test Page',
                'slug' => 'test-page',
                'area' => 'church',
                'markdown' => '# Hello World',
                'description' => 'Test page description',
                'navigation' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pages', [
            'heading' => 'Test Page',
            'slug' => 'test-page',
        ]);
    }

    public function test_can_render_edit_page(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        $this->get(PageResource::getUrl('edit', ['record' => $page->slug]))
            ->assertSuccessful();
    }

    public function test_can_update_page(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        Livewire::test(PageResource\Pages\EditPage::class, ['record' => $page->slug])
            ->fillForm([
                'heading' => 'Updated Heading',
                'markdown' => $page->markdown,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'heading' => 'Updated Heading',
        ]);
    }

    public function test_can_delete_page(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        Livewire::test(PageResource\Pages\EditPage::class, ['record' => $page->slug])
            ->callAction('delete');

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    public function test_markdown_converts_to_html(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(PageResource\Pages\CreatePage::class)
            ->fillForm([
                'heading' => 'Markdown Test',
                'slug' => 'markdown-test',
                'area' => 'church',
                'markdown' => '# Heading\n\nParagraph text.',
                'description' => 'Test description',
                'navigation' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = Page::where('slug', 'markdown-test')->first();
        $this->assertNotNull($page);
        $this->assertStringContainsString('<h1>', $page->body);
    }

    public function test_non_admin_cannot_access(): void
    {
        $regularUser = User::factory()->create([
            'email' => 'regular@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($regularUser);

        $this->get(PageResource::getUrl('index'))
            ->assertForbidden();
    }
}
