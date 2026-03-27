<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingControllerTest extends TestCase
{
    use DatabaseTransactions;

    // ── show (public) ──────────────────────────────────────────────────────

    #[Test]
    public function public_meeting_show_returns_200(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'youth-group',
            'day' => 'Friday',
            'start_time' => '19:00:00',
            'end_time' => '21:00:00',
            'location' => 'Church Hall',
            'who' => 'All ages',
            'type' => 'Adults',
        ]);

        $response = $this->get('/community/youth-group');
        $response->assertStatus(200);
        $response->assertSee('Church Hall');
    }

    #[Test]
    public function show_returns_404_for_nonexistent_meeting(): void
    {
        $response = $this->get('/community/does-not-exist');
        $response->assertStatus(404);
    }

    // ── index (admin, auth guarded) ────────────────────────────────────────

    #[Test]
    public function meeting_index_redirects_guests_to_login(): void
    {
        $response = $this->get(route('meetings.index'));
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function meeting_index_is_accessible_to_admins(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get(route('meetings.index'));
        $response->assertStatus(200);
    }

    #[Test]
    public function meeting_index_returns_403_for_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $response = $this->actingAs($user)->get(route('meetings.index'));
        $response->assertStatus(403);
    }

    // ── destroy (admin, auth guarded) ─────────────────────────────────────

    #[Test]
    public function destroy_redirects_guests_to_login(): void
    {
        $meeting = Meeting::factory()->create();

        $response = $this->delete(route('meetings.destroy', $meeting));
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function admin_can_destroy_a_meeting(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $meeting = Meeting::factory()->create(['slug' => 'to-be-deleted']);

        $response = $this->actingAs($admin)->delete(route('meetings.destroy', $meeting));

        $response->assertRedirect('/church/members/meetings');
        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }
}
