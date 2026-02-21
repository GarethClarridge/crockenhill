<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\Meetings\ListMeetings;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminMeetingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_resets_invalid_sort_input_to_safe_defaults(): void
    {
        $this->actingAs($this->admin);

        Meeting::factory()->create(['slug' => 'prayer-meeting']);

        Livewire::test(ListMeetings::class)
            ->set('sortBy', 'invalid_column')
            ->set('sortDirection', 'sideways')
            ->assertSet('sortBy', 'updated_at')
            ->assertSet('sortDirection', 'desc')
            ->assertSee('prayer-meeting');
    }

    #[Test]
    public function sort_action_rejects_non_allowlisted_columns(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->set('sortBy', 'day')
            ->set('sortDirection', 'asc')
            ->call('sort', 'invalid_column')
            ->assertSet('sortBy', 'updated_at')
            ->assertSet('sortDirection', 'desc');
    }
}
