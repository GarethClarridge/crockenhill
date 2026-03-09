<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingSeoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function meeting_page_contains_expected_seo_metadata()
    {
        $page = Page::factory()->create([
            'heading' => 'Buzz Club',
            'slug' => 'buzz-club',
            'description' => 'A fun club for kids.',
        ]);

        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'buzz-club',
            'day' => 'Friday',
            'start_time' => '18:00:00',
        ]);

        $response = $this->get("/community/buzz-club");

        $response->assertStatus(200);

        // Assert parts of the title
        $response->assertSee('<title>Buzz Club | Friday 6:00pm - Crockenhill Baptist Church</title>', false);

        $response->assertSee('A fun club for kids.', false);
    }

    #[Test]
    public function meeting_page_contains_breadcrumb_json_ld()
    {
        $page = Page::factory()->create([
            'heading' => 'Test Meeting',
            'slug' => 'test-meeting',
        ]);

        Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'test-meeting',
        ]);

        $response = $this->get("/community/test-meeting");

        $response->assertStatus(200);
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Home"', false);
        $response->assertSee('"name": "Community"', false);
        $response->assertSee('"name": "Test Meeting"', false);
    }

    #[Test]
    public function meeting_page_contains_event_json_ld_when_events_present()
    {
        $page = Page::factory()->create([
            'heading' => 'Test Meeting',
            'slug' => 'test-meeting',
        ]);

        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'test-meeting',
        ]);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Upcoming Test Event',
            'description' => 'This is an upcoming event.',
            'start_datetime' => now()->addDays(1)->setTime(10, 0, 0),
            'status' => 'confirmed',
        ]);

        $response = $this->get("/community/test-meeting");

        $response->assertStatus(200);

        // Use assertion that gives more info if it fails
        if (!str_contains($response->getContent(), '"@type": "Event"')) {
            echo "\nContent did not contain Event JSON-LD\n";
            echo "\nUpcoming Events Count in Controller: " . $meeting->calendarEvents()->upcoming()->confirmed()->count() . "\n";
        }

        $response->assertSee('"@type": "Event"', false);
    }
}
