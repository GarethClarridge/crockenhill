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
 * Meeting admin is at the canonical /admin/meetings path.
 * Legacy /church/members/meetings paths have been removed.
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
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

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
    public function test_admin_meetings_index_is_accessible()
    {
        $this->actingAs($this->adminUser);
        $response = $this->get('/admin/meetings');
        $response->assertStatus(200);
    }

    #[Test]
    public function test_admin_meetings_create_is_accessible()
    {
        $this->actingAs($this->adminUser);
        $response = $this->get('/admin/meetings/create');
        $response->assertStatus(200);
    }

    #[Test]
    public function test_admin_meetings_edit_is_accessible()
    {
        $meeting = Meeting::factory()->create();

        $this->actingAs($this->adminUser);
        $response = $this->get("/admin/meetings/{$meeting->slug}/edit");
        $response->assertStatus(200);
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
    public function test_meeting_show_uses_explicit_fallback_layout_data_without_a_page()
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'midweek-prayer',
            'page_id' => null,
        ]);

        $response = $this->get("/community/{$meeting->slug}");

        $response->assertOk();
        $this->assertSame('Midweek Prayer', $response->viewData('heading'));
        $this->assertSame('community', $response->viewData('area'));
        $this->assertSame('midweek-prayer', $response->viewData('slug'));
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
