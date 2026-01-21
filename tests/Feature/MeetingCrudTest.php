<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for Meeting CRUD operations.
 *
 * Note: Meeting admin has been migrated to Filament at /admin/meetings.
 * The legacy routes at /church/members/meetings now redirect to Filament.
 * This test file verifies the redirects work correctly and tests the
 * public meeting show functionality.
 */
class MeetingCrudTest extends TestCase
{
    use DatabaseTransactions;

    private User $adminUser;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable CSRF protection for these form tests
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        // Create admin user with unique email
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        // Create regular user with unique email
        $this->regularUser = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function test_legacy_meeting_index_redirects_to_filament()
    {
        $this->actingAs($this->adminUser);
        $response = $this->get('/church/members/meetings');
        $response->assertRedirect('/admin/meetings');
    }

    #[Test]
    public function test_legacy_meeting_create_redirects_to_filament()
    {
        $this->actingAs($this->adminUser);
        $response = $this->get('/church/members/meetings/create');
        $response->assertRedirect('/admin/meetings/create');
    }

    #[Test]
    public function test_legacy_meeting_edit_redirects_to_filament()
    {
        $meeting = Meeting::factory()->create();

        $this->actingAs($this->adminUser);
        $response = $this->get("/church/members/meetings/{$meeting->slug}/edit");
        $response->assertRedirect("/admin/meetings/{$meeting->slug}/edit");
    }

    #[Test]
    public function test_meeting_show_method_works_correctly()
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
            'pictures' => true,
        ]);

        $response = $this->get("/community/{$meeting->slug}");

        $response->assertStatus(200);
        $response->assertViewIs('meetings.show');
        $response->assertViewHas('meeting');
        $response->assertViewHas('photos');
        $response->assertViewHas('page');
    }

    #[Test]
    public function test_meeting_show_passes_page_data()
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting-with-page',
        ]);

        $response = $this->get("/community/{$meeting->slug}");

        $response->assertStatus(200);
        $response->assertViewIs('meetings.show');
        // page may be null if not linked
        $response->assertViewHas('page');
    }

    #[Test]
    public function test_meeting_show_loads_calendar_events()
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting-events',
        ]);

        $response = $this->get("/community/{$meeting->slug}");

        $response->assertStatus(200);
        $response->assertViewHas('upcomingEvents');
        $response->assertViewHas('pastEvents');
    }

    #[Test]
    public function test_meeting_show_404_for_nonexistent_meeting()
    {
        $response = $this->get('/community/nonexistent-meeting');
        $response->assertStatus(404);
    }
}
