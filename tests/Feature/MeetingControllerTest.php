<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Meeting;
// UserFactory not used directly
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Str;

class MeetingControllerTest extends TestCase
{
  use RefreshDatabase;

  protected $adminUser;
  protected $regularUser;

  protected function setUp(): void
  {
    parent::setUp();
    $this->adminUser = \App\Models\User::factory()->admin()->create();
    $this->regularUser = \App\Models\User::factory()->create(['is_admin' => false]);
  }

  // 1. Authentication/Authorization Tests (CUD actions)
  #[Test]
  public function guests_cannot_access_meeting_cud_routes()
  {
    $meeting = \App\Models\Meeting::factory()->create();

    $this->get('/meetings/create')->assertRedirect('/login');
    $this->post('/meetings', [])->assertRedirect('/login');
    $this->get("/meetings/{$meeting->id}/edit")->assertRedirect('/login');
    $this->put("/meetings/{$meeting->id}", [])->assertRedirect('/login');
    $this->delete("/meetings/{$meeting->id}")->assertRedirect('/login');
  }

  #[Test]
  public function regular_users_are_forbidden_from_meeting_cud_routes()
  {
    $this->actingAs($this->regularUser);
    $meeting = \App\Models\Meeting::factory()->create();

    $this->get('/meetings/create')->assertForbidden();
    $this->post('/meetings', [])->assertForbidden();
    $this->get("/meetings/{$meeting->id}/edit")->assertForbidden();
    $this->put("/meetings/{$meeting->id}", [])->assertForbidden();
    $this->delete("/meetings/{$meeting->id}")->assertForbidden();
  }

  // 2. testMeetingIndexPageLoads
  #[Test]
  public function meeting_index_page_is_publicly_accessible()
  {
    $meeting = \App\Models\Meeting::factory()->create();
    $response = $this->get('/meetings');
    $response->assertOk();
    $response->assertViewIs('meetings.index');
    $response->assertSee($meeting->slug); // Check for slug or another prominent detail
  }

  // 3. testMeetingShowPageLoads
  #[Test]
  public function meeting_show_page_is_publicly_accessible()
  {
    // Create a meeting with a specific slug to make assertion easier
    $slugToTest = 'public-meeting-details-' . Str::random(5);
    $meeting = \App\Models\Meeting::factory()->create(['slug' => $slugToTest, 'type' => 'Occasional']);
    $response = $this->get("/meetings/{$meeting->id}"); // Assuming show route uses ID or resolves slug
    $response->assertOk();
    $response->assertViewIs('meetings.show');
    $response->assertSee($slugToTest); // Assert based on slug or other displayed data
    $response->assertSee($meeting->type);
  }

  #[Test]
  public function meeting_show_page_returns_404_for_non_existent_meeting()
  {
    $this->get('/meetings/9999')->assertNotFound();
  }

  // 4. testMeetingCreatePageLoads
  #[Test]
  public function meeting_create_page_loads_for_admin_users()
  {
    $response = $this->actingAs($this->adminUser)->get('/meetings/create');
    $response->assertOk();
    $response->assertViewIs('meetings.create');
    // $response->assertSee('Create Meeting'); // This text might still be valid
  }

  // 5. testStoreNewMeeting
  #[Test]
  public function admin_user_can_store_new_meeting()
  {
    // The form would likely take a title-like field which is then slugified.
    // Let's assume there's a 'heading_for_slug' field in the form request that generates the slug.
    // Or, the controller takes a 'slug' directly if it's manually entered/validated for uniqueness.
    // For this test, we'll simulate posting data that would result in a specific slug.
    $slugToCreate = 'board-meeting-' . Str::random(5);
    $meetingData = [
      // 'name' => 'Board Meeting', // Removed: no 'name' field
      'slug' => $slugToCreate, // Assuming slug can be directly provided or is derived
      'type' => 'Adults',
      'day' => 'Monday',
      'location' => 'Conference Room A',
      'who' => 'Board Members',
      'pictures' => false,
      // Add other required fields based on Meeting model & table
      // 'meeting_date' => Carbon::now()->addWeek()->format('Y-m-d H:i:s'), // If these were part of original test
      // 'is_recurring' => true, // If these were part of original test
      // 'frequency' => 'monthly', // If these were part of original test
    ];

    $response = $this->actingAs($this->adminUser)->post('/meetings', $meetingData);

    $this->assertDatabaseHas('meetings', ['slug' => $slugToCreate, 'type' => 'Adults']);
    $response->assertRedirect('/meetings');
    $response->assertSessionHas('success');
  }

  #[Test]
  public function store_meeting_fails_with_invalid_data()
  {
    // Assuming 'slug' and 'type' are required.
    $response = $this->actingAs($this->adminUser)->post('/meetings', [
      'slug' => '', // Slug is required
      'type' => '', // Type is required
      // 'meeting_date' => 'not-a-date', // If this was part of original test
    ]);
    $response->assertSessionHasErrors(['slug', 'type']); // Check for relevant field errors
    $this->assertDatabaseCount('meetings', 0);
  }

  // 6. testMeetingEditPageLoads
  #[Test]
  public function meeting_edit_page_loads_for_admin_users()
  {
    $meeting = \App\Models\Meeting::factory()->create();
    $response = $this->actingAs($this->adminUser)->get("/meetings/{$meeting->id}/edit");
    $response->assertOk();
    $response->assertViewIs('meetings.edit');
    $response->assertSee($meeting->slug); // Check for slug or another property displayed in edit form
  }

  #[Test]
  public function meeting_edit_page_returns_404_for_non_existent_meeting()
  {
    $this->actingAs($this->adminUser)->get('/meetings/9999/edit')->assertNotFound();
  }

  // 7. testUpdateExistingMeeting
  #[Test]
  public function admin_user_can_update_existing_meeting()
  {
    $meeting = \App\Models\Meeting::factory()->create(['slug' => 'old-meeting-slug', 'type' => 'ChildrenAndYoungPeople']);
    $updatedSlug = 'updated-meeting-slug-' . Str::random(5);
    $updateData = [
      // 'name' => 'Updated Meeting Name', // Removed
      'slug' => $updatedSlug,
      'type' => 'Occasional',
      // 'meeting_date' => Carbon::now()->addMonth()->format('Y-m-d H:i:s'), // If part of original
      // 'is_recurring' => false, // If part of original
    ];

    $response = $this->actingAs($this->adminUser)->put("/meetings/{$meeting->id}", $updateData);

    $this->assertDatabaseHas('meetings', [
      'id' => $meeting->id,
      'slug' => $updatedSlug,
      'type' => 'Occasional',
      // 'is_recurring' => false, // if part of original
    ]);
    $response->assertRedirect('/meetings');
    $response->assertSessionHas('success');
  }

  #[Test]
  public function update_meeting_fails_with_invalid_data()
  {
    $meeting = \App\Models\Meeting::factory()->create(['slug' => 'original-slug', 'type' => 'Adults']);
    $originalSlug = $meeting->slug;
    $originalType = $meeting->type;

    $response = $this->actingAs($this->adminUser)->put("/meetings/{$meeting->id}", [
      'slug' => '', // Slug is required
      'type' => '', // Type is required
      // 'meeting_date' => 'invalid-date-format', // If part of original test
    ]);
    $response->assertSessionHasErrors(['slug', 'type']); // Check for relevant field errors

    $updatedMeeting = \App\Models\Meeting::find($meeting->id);
    $this->assertEquals($originalSlug, $updatedMeeting->slug);
    $this->assertEquals($originalType, $updatedMeeting->type);
  }

  // 8. testDestroyMeeting
  #[Test]
  public function admin_user_can_destroy_meeting()
  {
    $meeting = \App\Models\Meeting::factory()->create();

    $response = $this->actingAs($this->adminUser)->delete("/meetings/{$meeting->id}");

    $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    $response->assertRedirect('/meetings');
    $response->assertSessionHas('success');
  }

  #[Test]
  public function destroy_non_existent_meeting_returns_404()
  {
    $this->actingAs($this->adminUser)->delete('/meetings/9999')->assertNotFound();
  }
}
