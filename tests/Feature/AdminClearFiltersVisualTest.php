<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Meeting;
use App\Models\Preacher;
use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminClearFiltersVisualTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);

        // Ensure some data exists
        Meeting::factory()->create(['slug' => 'test-meeting']);
        Preacher::factory()->create(['name' => 'John Doe']);
        CalendarEvent::factory()->create(['title' => 'Test Event']);
    }

    public function test_meetings_admin_shows_clear_filters(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.meetings.index', ['search' => 'test']));
        $response->assertStatus(200);
        $response->assertSee('Clear Filters');
    }

    public function test_users_admin_shows_clear_filters(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.users.index', ['search' => 'admin']));
        $response->assertStatus(200);
        $response->assertSee('Clear Filters');
    }

    public function test_preachers_admin_shows_clear_filters(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.preachers.index', ['search' => 'John']));
        $response->assertStatus(200);
        $response->assertSee('Clear Filters');
    }

    public function test_calendar_events_admin_shows_clear_filters(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.calendar-events.index', ['search' => 'Event']));
        $response->assertStatus(200);
        $response->assertSee('Clear Filters');
    }
}
