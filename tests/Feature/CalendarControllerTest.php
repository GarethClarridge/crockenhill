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
            'end_datetime' => now()->addDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Upcoming Event',
        ]);

        // Past event
        $past = CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->subDays(1),
            'end_datetime' => now()->subDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Past Event',
        ]);

        // Unconfirmed event (tentative)
        $unconfirmed = CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->addDays(2),
            'end_datetime' => now()->addDays(2)->addHour(),
            'status' => 'tentative',
            'title' => 'Unconfirmed Event',
        ]);

        // Too far in the future (> 6 months)
        $farFuture = CalendarEvent::factory()->create([
            'meeting_slug' => 'test-meeting',
            'start_datetime' => now()->addMonths(7),
            'end_datetime' => now()->addMonths(7)->addHour(),
            'status' => 'confirmed',
            'title' => 'Far Future Event',
        ]);

        $response = $this->get(route('calendar.index'));

        $response->assertStatus(200);
        $response->assertSee('Upcoming Event');
        $response->assertDontSee('Past Event');
        $response->assertDontSee('Unconfirmed Event');
        $response->assertDontSee('Far Future Event');

        // Check SEO tags
        $response->assertSee('<title>Church Calendar | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Upcoming events at Crockenhill Baptist Church.">', false);
        $response->assertSee('<meta property="og:title" content="Church Calendar | Crockenhill Baptist Church">', false);

        // Check JSON-LD
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"@type": "Event"', false);
        $response->assertSee('"name": "Upcoming Event"', false);

        // Check BreadcrumbList JSON-LD
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Community"', false);
        $response->assertSee('"name": "Church Calendar"', false);
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
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
        ]);

        $event2 = CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Tentative Meeting Event',
            'status' => 'tentative',
            'start_datetime' => now()->addDays(2),
            'end_datetime' => now()->addDays(2)->addHour(),
        ]);

        $cancelledEvent = CalendarEvent::factory()->create([
            'meeting_slug' => 'specific-meeting',
            'title' => 'Cancelled Event',
            'status' => 'cancelled',
            'start_datetime' => now()->addDays(3),
            'end_datetime' => now()->addDays(3)->addHour(),
        ]);

        $otherEvent = CalendarEvent::factory()->create([
            'meeting_slug' => 'other-meeting',
            'title' => 'Other Meeting Event',
            'status' => 'confirmed',
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
        ]);

        $response = $this->get(route('meetings.events', $meeting));

        $response->assertStatus(200);
        $response->assertSee('Meeting Event 1');
        $response->assertDontSee('Tentative Meeting Event');
        $response->assertDontSee('Cancelled Event');
        $response->assertDontSee('Other Meeting Event');

        // Check BreadcrumbList JSON-LD
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Community"', false);
        $response->assertSee('"name": "Specific Meeting"', false);
        $response->assertSee('"name": "specific-meeting events"', false);
    }

    #[Test]
    public function uncategorized_calendar_shows_upcoming_uncategorized_events(): void
    {
        Meeting::factory()->create(['slug' => 'some-meeting']);

        $uncategorizedUpcoming = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Uncategorized Upcoming',
        ]);

        $uncategorizedPast = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'start_datetime' => now()->subDays(1),
            'end_datetime' => now()->subDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Uncategorized Past',
        ]);

        $uncategorizedTentative = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'start_datetime' => now()->addDays(2),
            'end_datetime' => now()->addDays(2)->addHour(),
            'status' => 'tentative',
            'title' => 'Uncategorized Tentative',
        ]);

        $categorizedUpcoming = CalendarEvent::factory()->create([
            'meeting_slug' => 'some-meeting',
            'start_datetime' => now()->addDays(1),
            'end_datetime' => now()->addDays(1)->addHour(),
            'status' => 'confirmed',
            'title' => 'Categorized Upcoming',
        ]);

        $response = $this->get(route('calendar.uncategorized'));

        $response->assertStatus(200);
        $response->assertSee('Uncategorized Upcoming');
        $response->assertDontSee('Uncategorized Past');
        $response->assertDontSee('Uncategorized Tentative');
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
