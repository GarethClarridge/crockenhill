<?php

namespace Tests\Feature;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create admin user
        $this->adminUser = User::factory()->create([
            'email' => 'admin@crockenhill.org',
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        
        // Create regular user
        $this->regularUser = User::factory()->create([
            'email' => 'user@example.com',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function test_meeting_index_requires_authentication()
    {
        $response = $this->get('/church/members/meetings');
        $response->assertRedirect('/login');
    }

    #[Test]
    public function test_meeting_index_requires_admin_authorization()
    {
        $this->actingAs($this->regularUser);
        $response = $this->get('/church/members/meetings');
        $response->assertStatus(403);
    }

    #[Test]
    public function test_admin_can_view_meeting_index()
    {
        $meetings = Meeting::factory()->count(3)->create();
        
        $this->actingAs($this->adminUser);
        $response = $this->get('/church/members/meetings');
        
        $response->assertStatus(200);
        $response->assertViewIs('meetings.index');
        $response->assertViewHas('meetings');
        
        foreach ($meetings as $meeting) {
            $response->assertSee($meeting->slug);
        }
    }

    #[Test]
    public function test_admin_can_view_meeting_create_form()
    {
        $this->actingAs($this->adminUser);
        $response = $this->get('/church/members/meetings/create');
        
        $response->assertStatus(200);
        $response->assertViewIs('meetings.create');
        $response->assertSee('Create a meeting');
    }

    #[Test]
    public function test_regular_user_cannot_view_create_form()
    {
        $this->actingAs($this->regularUser);
        $response = $this->get('/church/members/meetings/create');
        $response->assertStatus(403);
    }

    #[Test]
    public function test_admin_can_create_meeting_with_valid_data()
    {
        $this->actingAs($this->adminUser);
        
        $meetingData = [
            'slug' => 'test-meeting',
            'type' => MeetingType::ADULTS->value,
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'day' => 'Wednesday',
            'location' => '123 Main St, Church Hall',
            'who' => 'All adults welcome',
            'pictures' => '1',
            'LeadersPhone' => '1234567890',
            'LeadersEmail' => 'leader@example.com',
            'meeting_date' => '2024-01-15',
            'is_recurring' => '1',
            'frequency' => MeetingFrequency::WEEKLY->value,
        ];
        
        $response = $this->post('/church/members/meetings', $meetingData);
        
        $response->assertRedirect('/church/members/meetings');
        $response->assertSessionHas('message');
        
        $this->assertDatabaseHas('meetings', [
            'slug' => 'test-meeting',
            'type' => MeetingType::ADULTS->value,
            'day' => 'Wednesday',
            'location' => '123 Main St, Church Hall',
            'who' => 'All adults welcome',
            'pictures' => true,
            'LeadersPhone' => '1234567890',
            'LeadersEmail' => 'leader@example.com',
            'is_recurring' => true,
            'frequency' => MeetingFrequency::WEEKLY->value,
        ]);
    }

    #[Test]
    public function test_meeting_creation_validates_required_fields()
    {
        $this->actingAs($this->adminUser);
        
        $response = $this->post('/church/members/meetings', []);
        
        $response->assertSessionHasErrors([
            'slug',
            'type',
            'day',
            'who',
            'pictures',
        ]);
    }

    #[Test]
    public function test_meeting_creation_validates_unique_slug()
    {
        $existingMeeting = Meeting::factory()->create(['slug' => 'existing-meeting']);
        
        $this->actingAs($this->adminUser);
        
        $response = $this->post('/church/members/meetings', [
            'slug' => 'existing-meeting',
            'type' => MeetingType::ADULTS->value,
            'pictures' => '1',
        ]);
        
        $response->assertSessionHasErrors(['slug']);
    }

    #[Test]
    public function test_meeting_creation_validates_enum_values()
    {
        $this->actingAs($this->adminUser);
        
        $response = $this->post('/church/members/meetings', [
            'slug' => 'test-meeting',
            'type' => 'invalid-type',
            'pictures' => '1',
            'frequency' => 'invalid-frequency',
        ]);
        
        $response->assertSessionHasErrors(['type']);
    }

    #[Test]
    public function test_meeting_creation_validates_time_format()
    {
        $this->actingAs($this->adminUser);
        
        $response = $this->post('/church/members/meetings', [
            'slug' => 'test-meeting',
            'type' => MeetingType::ADULTS->value,
            'pictures' => '1',
            'StartTime' => 'invalid-time',
            'EndTime' => '25:00:00',
        ]);
        
        $response->assertSessionHasErrors(['StartTime', 'EndTime']);
    }

    #[Test]
    public function test_meeting_creation_validates_email_format()
    {
        $this->actingAs($this->adminUser);
        
        $response = $this->post('/church/members/meetings', [
            'slug' => 'test-meeting',
            'type' => MeetingType::ADULTS->value,
            'pictures' => '1',
            'LeadersEmail' => 'invalid-email',
        ]);
        
        $response->assertSessionHasErrors(['LeadersEmail']);
    }

    #[Test]
    public function test_admin_can_view_meeting_edit_form()
    {
        $meeting = Meeting::factory()->create();
        
        $this->actingAs($this->adminUser);
        $response = $this->get("/church/members/meetings/{$meeting->slug}/edit");
        
        $response->assertStatus(200);
        $response->assertViewIs('meetings.edit');
        $response->assertViewHas('meeting', $meeting);
        $response->assertSee($meeting->slug);
    }

    #[Test]
    public function test_regular_user_cannot_view_edit_form()
    {
        $meeting = Meeting::factory()->create();
        
        $this->actingAs($this->regularUser);
        $response = $this->get("/church/members/meetings/{$meeting->slug}/edit");
        $response->assertStatus(403);
    }

    #[Test]
    public function test_admin_can_update_meeting()
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'original-meeting',
            'type' => MeetingType::ADULTS->value,
            'day' => 'Monday',
        ]);
        
        $this->actingAs($this->adminUser);
        
        $updateData = [
            'slug' => 'updated-meeting',
            'type' => MeetingType::CHILDREN_AND_YOUNG_PEOPLE->value,
            'day' => 'Tuesday',
            'location' => 'Updated Location',
            'who' => 'Updated audience',
            'pictures' => '0',
            'LeadersPhone' => '0987654321',
            'LeadersEmail' => 'updated@example.com',
            'meeting_date' => '2024-02-20',
            'is_recurring' => '0',
        ];
        
        $response = $this->put("/church/members/meetings/{$meeting->slug}", $updateData);
        
        $response->assertRedirect('/church/members/meetings');
        $response->assertSessionHas('message');
        
        $meeting->refresh();
        $this->assertEquals('updated-meeting', $meeting->slug);
        $this->assertEquals(MeetingType::CHILDREN_AND_YOUNG_PEOPLE, $meeting->type);
        $this->assertEquals('Tuesday', $meeting->day);
        $this->assertEquals('Updated Location', $meeting->location);
        $this->assertEquals('Updated audience', $meeting->who);
        $this->assertFalse($meeting->pictures);
        $this->assertEquals('0987654321', $meeting->LeadersPhone);
        $this->assertEquals('updated@example.com', $meeting->LeadersEmail);
        $this->assertFalse($meeting->is_recurring);
    }

    #[Test]
    public function test_meeting_update_validates_unique_slug_except_current()
    {
        $meeting1 = Meeting::factory()->create(['slug' => 'meeting-1']);
        $meeting2 = Meeting::factory()->create(['slug' => 'meeting-2']);
        
        $this->actingAs($this->adminUser);
        
        // Should fail - trying to use existing slug
        $response = $this->put("/church/members/meetings/{$meeting2->slug}", [
            'slug' => 'meeting-1',
            'type' => MeetingType::ADULTS->value,
            'day' => 'Friday',
            'who' => 'All welcome',
            'pictures' => '1',
        ]);
        
        $response->assertSessionHasErrors(['slug']);
        
        // Should pass - keeping same slug
        $response = $this->put("/church/members/meetings/{$meeting2->slug}", [
            'slug' => 'meeting-2',
            'type' => MeetingType::ADULTS->value,
            'day' => 'Friday',
            'who' => 'All welcome',
            'pictures' => '1',
        ]);
        
        $response->assertRedirect('/church/members/meetings');
        $response->assertSessionHasNoErrors();
    }

    #[Test]
    public function test_admin_can_delete_meeting()
    {
        $meeting = Meeting::factory()->create();
        
        $this->actingAs($this->adminUser);
        
        $response = $this->delete("/church/members/meetings/{$meeting->slug}");
        
        $response->assertRedirect('/church/members/meetings');
        $response->assertSessionHas('message');
        
        $this->assertDatabaseMissing('meetings', [
            'id' => $meeting->id,
        ]);
    }

    #[Test]
    public function test_regular_user_cannot_delete_meeting()
    {
        $meeting = Meeting::factory()->create();
        
        $this->actingAs($this->regularUser);
        
        $response = $this->delete("/church/members/meetings/{$meeting->slug}");
        $response->assertStatus(403);
        
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
        ]);
    }

    #[Test]
    public function test_meeting_crud_handles_all_enum_values()
    {
        $this->actingAs($this->adminUser);
        
        foreach (MeetingType::cases() as $type) {
            $meetingData = [
                'slug' => 'test-meeting-' . $type->value,
                'type' => $type->value,
                'day' => 'Monday',
                'who' => 'All welcome',
                'pictures' => '1',
            ];
            
            $response = $this->post('/church/members/meetings', $meetingData);
            $response->assertRedirect('/church/members/meetings');
            
            $this->assertDatabaseHas('meetings', [
                'slug' => 'test-meeting-' . $type->value,
                'type' => $type->value,
            ]);
        }
    }

    #[Test]
    public function test_meeting_crud_handles_all_frequency_values()
    {
        $this->actingAs($this->adminUser);
        
        foreach (MeetingFrequency::cases() as $frequency) {
            $meetingData = [
                'slug' => 'test-meeting-' . $frequency->value,
                'type' => MeetingType::ADULTS->value,
                'day' => 'Tuesday',
                'who' => 'All welcome',
                'pictures' => '1',
                'is_recurring' => '1',
                'frequency' => $frequency->value,
            ];
            
            $response = $this->post('/church/members/meetings', $meetingData);
            $response->assertRedirect('/church/members/meetings');
            
            $this->assertDatabaseHas('meetings', [
                'slug' => 'test-meeting-' . $frequency->value,
                'frequency' => $frequency->value,
            ]);
        }
    }

    #[Test]
    public function test_meeting_show_method_works_correctly()
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
            'pictures' => true,
        ]);
        
        $response = $this->get("/meetings/{$meeting->slug}");
        
        $response->assertStatus(200);
        $response->assertViewIs('meetings.show');
        $response->assertViewHas('meeting', $meeting);
        $response->assertViewHas('photos');
    }

    #[Test]
    public function test_meeting_form_handles_boolean_fields_correctly()
    {
        $this->actingAs($this->adminUser);
        
        // Test with pictures = true, is_recurring = true
        $response = $this->post('/church/members/meetings', [
            'slug' => 'test-meeting-true',
            'type' => MeetingType::ADULTS->value,
            'day' => 'Wednesday',
            'who' => 'All welcome',
            'pictures' => '1',
            'is_recurring' => '1',
            'frequency' => MeetingFrequency::WEEKLY->value,
        ]);
        
        $response->assertRedirect('/church/members/meetings');
        $this->assertDatabaseHas('meetings', [
            'slug' => 'test-meeting-true',
            'pictures' => true,
            'is_recurring' => true,
        ]);
        
        // Test with pictures = false, is_recurring = false
        $response = $this->post('/church/members/meetings', [
            'slug' => 'test-meeting-false',
            'type' => MeetingType::ADULTS->value,
            'day' => 'Thursday',
            'who' => 'All welcome',
            'pictures' => '0',
            'is_recurring' => '0',
        ]);
        
        $response->assertRedirect('/church/members/meetings');
        $this->assertDatabaseHas('meetings', [
            'slug' => 'test-meeting-false',
            'pictures' => false,
            'is_recurring' => false,
        ]);
    }

    #[Test]
    public function test_meeting_form_accepts_time_in_hi_format()
    {
        $this->actingAs($this->adminUser);
        
        // Test that time inputs work with H:i format (what HTML time inputs send)
        $response = $this->post('/church/members/meetings', [
            'slug' => 'test-meeting-time',
            'type' => MeetingType::ADULTS->value,
            'day' => 'Tuesday',
            'who' => 'All welcome',
            'pictures' => '1',
            'StartTime' => '19:00',  // H:i format
            'EndTime' => '21:00',    // H:i format
        ]);
        
        $response->assertRedirect('/church/members/meetings');
        $response->assertSessionHasNoErrors();
        
        $this->assertDatabaseHas('meetings', [
            'slug' => 'test-meeting-time',
            'StartTime' => '19:00:00',  // Should be stored as H:i:s
            'EndTime' => '21:00:00',    // Should be stored as H:i:s
        ]);
    }
}