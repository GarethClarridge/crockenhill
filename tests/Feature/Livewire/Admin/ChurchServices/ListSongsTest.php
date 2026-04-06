<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Livewire\Admin\ChurchServices\ListSongs;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListSongsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function it_renders_successfully(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListSongs::class)
            ->assertStatus(200)
            ->assertSee('Songs');
    }

    #[Test]
    public function it_filters_by_search_term(): void
    {
        Song::factory()->create(['title' => 'Amazing Grace']);
        Song::factory()->create(['title' => 'In Christ Alone']);

        Livewire::actingAs($this->admin)
            ->test(ListSongs::class)
            ->set('search', 'Amazing')
            ->assertSee('Amazing Grace')
            ->assertDontSee('In Christ Alone');
    }

    #[Test]
    public function it_shows_empty_state_with_clear_filters_button_when_filtering_returns_no_results(): void
    {
        Song::factory()->create(['title' => 'Amazing Grace']);

        Livewire::actingAs($this->admin)
            ->test(ListSongs::class)
            ->set('search', 'NonExistent')
            ->assertSee('No songs found')
            ->assertSee('Clear all filters');
    }

    #[Test]
    public function it_resets_filters_successfully(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListSongs::class)
            ->set('search', 'Some Search')
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('hasFilters', false);
    }
}
