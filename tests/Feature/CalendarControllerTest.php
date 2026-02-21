<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarControllerTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function calendar_index_shows_upcoming_confirmed_events(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'test-meeting']);

        // Upcoming confirmed event
        $upcoming = CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->addDays(1),
            'status' => 'confirmed',
            'title' => 'Upcoming Event',
        ]);

        // Past event
        $past = CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->subDays(1),
            'status' => 'confirmed',
            'title' => 'Past Event',
        ]);

        // Unconfirmed event (tentative)
        $unconfirmed = CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->addDays(2),
            'status' => 'tentative',
            'title' => 'Unconfirmed Event',
        ]);

        // Too far in the future (> 6 months)
        $farFuture = CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->addMonths(7),
            'status' => 'confirmed',
            'title' => 'Far Future Event',
        ]);

        $response = $this->get(route('calendar.index'));

        $response->assertStatus(200);
        $response->assertSee('Upcoming Event');
        $response->assertDontSee('Past Event');
        $response->assertDontSee('Unconfirmed Event');
        $response->assertDontSee('Far Future Event');
    }

    #[Test]
    public function events_for_meeting_shows_events_for_that_meeting_not_cancelled(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'specific-meeting']);
        $otherMeeting = Meeting::factory()->create(['slug' => 'other-meeting']);

        $event1 = CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Meeting Event 1',
            'status' => 'confirmed',
        ]);

        $event2 = CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Meeting Event 2',
            'status' => 'tentative', // Should be shown as it's not 'cancelled'
        ]);

        $cancelledEvent = CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Cancelled Event',
            'status' => 'cancelled', // Should NOT be shown
        ]);

        $otherEvent = CalendarEvent::factory()->create([
            'meeting_slug' => 'other-meeting',
            'title' => 'Other Meeting Event',
            'status' => 'confirmed',
        ]);

        $response = $this->get(route('meetings.events', $meeting));

        $response->assertStatus(200);
        $response->assertSee('Meeting Event 1');
        $response->assertSee('Meeting Event 2');
        $response->assertDontSee('Cancelled Event');
        $response->assertDontSee('Other Meeting Event');
    }

    #[Test]
    public function uncategorized_calendar_shows_upcoming_uncategorized_events(): void
    {
        config(['calendar.uncategorized_slug' => 'uncategorized']);

        $uncategorizedUpcoming = CalendarEvent::factory()->create([
            'meeting_slug' => 'uncategorized',
            'start_datetime' => now()->addDays(1),
            'title' => 'Uncategorized Upcoming',
        ]);

        $uncategorizedPast = CalendarEvent::factory()->create([
            'meeting_slug' => 'uncategorized',
            'start_datetime' => now()->subDays(1),
            'title' => 'Uncategorized Past',
        ]);

        $categorizedUpcoming = CalendarEvent::factory()->create([
            'meeting_slug' => 'some-meeting',
            'start_datetime' => now()->addDays(1),
            'title' => 'Categorized Upcoming',
        ]);

        $response = $this->get(route('calendar.uncategorized'));

        $response->assertStatus(200);
        $response->assertSee('Uncategorized Upcoming');
        $response->assertDontSee('Uncategorized Past');
        $response->assertDontSee('Categorized Upcoming');
    }

    #[Test]
    public function calendar_index_handles_no_events_gracefully(): void
    {
        // Ensure no events exist
        CalendarEvent::query()->delete();

        $response = $this->get(route('calendar.index'));

        $response->assertStatus(200);
        $response->assertSee('No upcoming events');
    }
}
