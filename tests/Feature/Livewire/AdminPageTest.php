<?php

namespace Tests\Feature\Livewire;

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
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_renders_successfully()
    {
        $this->actingAs($this->admin);

        Livewire::test(ListPages::class)
            ->assertStatus(200)
            ->assertSee('Pages');
    }

    #[Test]
    public function it_can_search_pages()
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
    public function it_can_filter_by_area()
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
    public function it_can_delete_a_page()
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        Livewire::test(ListPages::class)
            ->call('delete', $page)
            ->assertDispatched('notify');

        $this->assertModelMissing($page);
    }

    #[Test]
    public function it_can_delete_multiple_pages()
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
    public function it_can_sort_pages()
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
    public function it_resets_invalid_sort_input_to_safe_defaults()
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
}
