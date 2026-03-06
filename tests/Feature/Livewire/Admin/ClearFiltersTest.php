<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ClearFiltersTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_can_reset_sermon_filters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListSermons::class)
            ->set('search', 'Test Search')
            ->set('serviceFilter', 'morning')
            ->set('last12Months', false)
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('serviceFilter', null)
            ->assertSet('last12Months', true)
            ->assertSet('hasFilters', false);
    }

    public function test_can_reset_page_filters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListPages::class)
            ->set('search', 'Page Search')
            ->set('areaFilter', 'christ')
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('areaFilter', null)
            ->assertSet('hasFilters', false);
    }
}
