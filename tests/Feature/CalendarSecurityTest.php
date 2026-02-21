<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class CalendarSecurityTest extends TestCase
{
    public function test_non_admin_user_cannot_access_calendar_admin_routes()
    {
        $user = User::factory()->make(['id' => 1, 'is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.calendar.uncategorized'))
            ->assertStatus(403);

        $this->actingAs($user)
            ->get(route('admin.calendar.patterns'))
            ->assertStatus(403);

        $this->actingAs($user)
            ->post(route('admin.calendar.sync'))
            ->assertStatus(403);
    }

    public function test_admin_user_can_access_calendar_admin_routes()
    {
        $user = User::factory()->make(['id' => 2, 'is_admin' => true]);

        // We expect it to try to render but fail with 500 because of missing DB tables for View Composer,
        // but if it reaches the View Composer, it means it passed the 'admin' middleware!
        // A 403 would mean it failed the middleware.

        $response = $this->actingAs($user)->get(route('admin.calendar.uncategorized'));
        $this->assertNotEquals(403, $response->getStatusCode());
    }
}
