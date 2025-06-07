<?php

namespace Tests\Feature;

use Tests\TestCase;
use Crockenhill\Meeting;
use Crockenhill\User;
use Crockenhill\Sermon; // Added for factory creation during previous steps
use Crockenhill\Page;   // Added for factory creation during previous steps
use Illuminate\Support\Str; // THIS IS THE NEW LINE TO ADD
use Illuminate\Foundation\Testing\RefreshDatabase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function an_authorised_user_can_create_a_meeting()
    {
        // $this->withoutExceptionHandling(); // Ensure this is off for now
        // $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class); // Ensure this is off

        $user = User::factory()->create();
        $meetingData = Meeting::factory()->raw();

        $response = $this->actingAs($user)->post('http://localhost/community', $meetingData);

        // $response->dumpSession(); // Create test is passing

        // If dd() in controller is hit, this won't be reached. Otherwise, check original assertions.
        $response->assertStatus(302);
        $this->assertDatabaseHas('meetings', ['name' => $meetingData['name']]);
    }

    /** @test */
    public function a_meeting_can_be_read()
    {
        $user = User::factory()->create();
        // Ensure distinct, very past dates and specific services for ViewServiceProvider
        \Crockenhill\Sermon::factory()->create(['service' => 'morning', 'date' => '2023-01-01']);
        \Crockenhill\Sermon::factory()->create(['service' => 'evening', 'date' => '2023-01-02']);

        // Pages required by community.blade.php and potentially its layout/footer
        $pagesToCreate = [
            'community', 'coffee-cup', 'baby-talk', 'sunday-mornings',
            'family-talk', 'buzz-club', 'christianity-explored',
            'bible-study', 'carols-in-the-chequers'
        ];
        foreach ($pagesToCreate as $slug) {
            \Crockenhill\Page::factory()->create(['slug' => $slug, 'area' => 'community', 'title' => Str::title(str_replace('-', ' ', $slug))]);
        }

        $meeting = Meeting::factory()->create();

        $response = $this->actingAs($user)->get('http://localhost/community/' . $meeting->id);

        $response->assertStatus(200);
        $response->assertSee($meeting->name);
    }

    /** @test */
    public function an_authorised_user_can_update_a_meeting()
    {
        $user = User::factory()->create(); // Ensured actingAs
        $this->actingAs($user);
        $meeting = Meeting::factory()->create();

        $updatedData = [ // Match factory fields for consistency if possible
            'name' => 'Updated Meeting Name',
            'description' => 'Updated meeting description.',
            'day' => 'Monday',
            'StartTime' => '14:00:00', // Corrected from 'time' to 'StartTime'
            'location' => 'New Location'
        ];

        $response = $this->put('http://localhost/community/' . $meeting->id, $updatedData);

        $response->assertStatus(302);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'name' => 'Updated Meeting Name']);
    }

    /** @test */
    public function an_authorised_user_can_delete_a_meeting()
    {
        $user = User::factory()->create(); // Ensured actingAs
        $this->actingAs($user);
        $meeting = Meeting::factory()->create();

        $response = $this->delete('http://localhost/community/' . $meeting->id);

        $response->assertStatus(302);
        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    // Basic test for listing meetings
    /** @test */
    public function meetings_can_be_listed()
    {
        $user = User::factory()->create();
        // Ensure distinct, very past dates and specific services for ViewServiceProvider
        \Crockenhill\Sermon::factory()->create(['service' => 'morning', 'date' => '2023-01-01']);
        \Crockenhill\Sermon::factory()->create(['service' => 'evening', 'date' => '2023-01-02']);

        // Pages required by community.blade.php and potentially its layout/footer
        $pagesToCreate = [
            'community', 'coffee-cup', 'baby-talk', 'sunday-mornings',
            'family-talk', 'buzz-club', 'christianity-explored',
            'bible-study', 'carols-in-the-chequers'
        ];
        foreach ($pagesToCreate as $slug) {
            // Ensure 'area' is set, as page-card component seems to need it.
            \Crockenhill\Page::factory()->create(['slug' => $slug, 'area' => 'community', 'title' => Str::title(str_replace('-', ' ', $slug))]);
        }


        Meeting::factory(3)->create();

        $response = $this->actingAs($user)->get('http://localhost/community');

        $response->assertStatus(200);
        // Add more assertions here, e.g., checking for the presence of meeting names in the view
    }
}
