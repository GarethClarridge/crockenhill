<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Meeting;
use Database\Factories\UserFactory;
use Database\Factories\MeetingFactory;
use Carbon\Carbon;

class MeetingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->admin()->create();
        $this->regularUser = User::factory()->create(['is_admin_for_test' => false]);
    }

    // 1. Authentication/Authorization Tests (CUD actions)
    /** @test */
    public function guests_cannot_access_meeting_cud_routes()
    {
        $meeting = Meeting::factory()->create();

        $this->get('/meetings/create')->assertRedirect('/login');
        $this->post('/meetings', [])->assertRedirect('/login');
        $this->get("/meetings/{$meeting->id}/edit")->assertRedirect('/login');
        $this->put("/meetings/{$meeting->id}", [])->assertRedirect('/login');
        $this->delete("/meetings/{$meeting->id}")->assertRedirect('/login');
    }

    /** @test */
    public function regular_users_are_forbidden_from_meeting_cud_routes()
    {
        $this->actingAs($this->regularUser);
        $meeting = Meeting::factory()->create();

        $this->get('/meetings/create')->assertForbidden();
        $this->post('/meetings', [])->assertForbidden();
        $this->get("/meetings/{$meeting->id}/edit")->assertForbidden();
        $this->put("/meetings/{$meeting->id}", [])->assertForbidden();
        $this->delete("/meetings/{$meeting->id}")->assertForbidden();
    }

    // 2. testMeetingIndexPageLoads
    /** @test */
    public function meeting_index_page_is_publicly_accessible()
    {
        Meeting::factory()->count(3)->create();
        $response = $this->get('/meetings');
        $response->assertOk();
        $response->assertViewIs('meetings.index'); // Assuming a view name
        // $response->assertSee(Meeting::first()->name);
    }

    // 3. testMeetingShowPageLoads
    /** @test */
    public function meeting_show_page_is_publicly_accessible()
    {
        $meeting = Meeting::factory()->create(['name' => 'Public Meeting Details']);
        $response = $this->get("/meetings/{$meeting->id}");
        $response->assertOk();
        $response->assertViewIs('meetings.show'); // Assuming a view name
        $response->assertSee('Public Meeting Details');
    }

    /** @test */
    public function meeting_show_page_returns_404_for_non_existent_meeting()
    {
        $this->get('/meetings/9999')->assertNotFound();
    }

    // 4. testMeetingCreatePageLoads
    /** @test */
    public function meeting_create_page_loads_for_admin_users()
    {
        $response = $this->actingAs($this->adminUser)->get('/meetings/create');
        $response->assertOk();
        $response->assertViewIs('meetings.create');
        // $response->assertSee('Create Meeting');
    }

    // 5. testStoreNewMeeting
    /** @test */
    public function admin_user_can_store_new_meeting()
    {
        $meetingData = [
            'name' => 'Board Meeting',
            'meeting_date' => Carbon::now()->addWeek()->format('Y-m-d H:i:s'),
            'location_name' => 'Conference Room A',
            'is_recurring' => true,
            'frequency' => 'monthly',
        ];

        $response = $this->actingAs($this->adminUser)->post('/meetings', $meetingData);

        $this->assertDatabaseHas('meetings', ['name' => 'Board Meeting', 'frequency' => 'monthly']);
        $response->assertRedirect('/meetings'); // Or to the new meeting's page
        $response->assertSessionHas('success');
    }

    /** @test */
    public function store_meeting_fails_with_invalid_data()
    {
        $response = $this->actingAs($this->adminUser)->post('/meetings', [
            'name' => '', // Name is required
            'meeting_date' => 'not-a-date',
        ]);
        $response->assertSessionHasErrors(['name', 'meeting_date']);
        $this->assertDatabaseCount('meetings', 0);
    }

    // 6. testMeetingEditPageLoads
    /** @test */
    public function meeting_edit_page_loads_for_admin_users()
    {
        $meeting = Meeting::factory()->create();
        $response = $this->actingAs($this->adminUser)->get("/meetings/{$meeting->id}/edit");
        $response->assertOk();
        $response->assertViewIs('meetings.edit');
        $response->assertSee($meeting->name);
    }

    /** @test */
    public function meeting_edit_page_returns_404_for_non_existent_meeting()
    {
        $this->actingAs($this->adminUser)->get('/meetings/9999/edit')->assertNotFound();
    }

    // 7. testUpdateExistingMeeting
    /** @test */
    public function admin_user_can_update_existing_meeting()
    {
        $meeting = Meeting::factory()->create(['name' => 'Old Meeting Name']);
        $updateData = [
            'name' => 'Updated Meeting Name',
            'meeting_date' => Carbon::now()->addMonth()->format('Y-m-d H:i:s'),
            'is_recurring' => false,
        ];

        $response = $this->actingAs($this->adminUser)->put("/meetings/{$meeting->id}", $updateData);

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'name' => 'Updated Meeting Name',
            'is_recurring' => false,
        ]);
        $response->assertRedirect('/meetings'); // Or to the meeting's page
        $response->assertSessionHas('success');
    }

    /** @test */
    public function update_meeting_fails_with_invalid_data()
    {
        $meeting = Meeting::factory()->create();
        $originalName = $meeting->name;
        $response = $this->actingAs($this->adminUser)->put("/meetings/{$meeting->id}", [
            'name' => '', // Name is required
            'meeting_date' => 'invalid-date-format',
        ]);
        $response->assertSessionHasErrors(['name', 'meeting_date']);
        $this->assertEquals($originalName, Meeting::find($meeting->id)->name);
    }

    // 8. testDestroyMeeting
    /** @test */
    public function admin_user_can_destroy_meeting()
    {
        $meeting = Meeting::factory()->create();

        $response = $this->actingAs($this->adminUser)->delete("/meetings/{$meeting->id}");

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
        $response->assertRedirect('/meetings');
        $response->assertSessionHas('success');
    }

    /** @test */
    public function destroy_non_existent_meeting_returns_404()
    {
        $this->actingAs($this->adminUser)->delete('/meetings/9999')->assertNotFound();
    }
}
