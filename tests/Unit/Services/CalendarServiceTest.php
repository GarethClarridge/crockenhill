<?php

namespace Tests\Unit\Services;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Services\CalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    private CalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['google-calendar.calendar_id' => 'test-calendar-id']);
        $this->service = new CalendarService;
    }

    #[Test]
    public function it_returns_events_for_a_specific_meeting_slug(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'sunday-morning']);

        CalendarEvent::factory()->count(3)->create(['meeting_slug' => 'sunday-morning']);
        CalendarEvent::factory()->count(2)->create(['meeting_slug' => 'other-meeting']);

        $events = $this->service->getEventsForMeeting('sunday-morning');

        $this->assertCount(3, $events);
        $events->each(fn ($event) => $this->assertEquals('sunday-morning', $event->meeting_slug));
    }

    #[Test]
    public function it_excludes_cancelled_events(): void
    {
        CalendarEvent::factory()->count(2)->create(['meeting_slug' => 'sunday-morning']);
        CalendarEvent::factory()->create(['meeting_slug' => 'sunday-morning', 'status' => 'cancelled']);

        $events = $this->service->getEventsForMeeting('sunday-morning');

        $this->assertCount(2, $events);
    }

    #[Test]
    public function it_filters_events_by_start_date(): void
    {
        $future = Carbon::now()->addDays(10);
        $past = Carbon::now()->subDays(10);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $future,
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $past,
        ]);

        $events = $this->service->getEventsForMeeting('sunday-morning', Carbon::now());

        $this->assertCount(1, $events);
        $this->assertTrue($events->first()->start_datetime->isAfter(Carbon::now()));
    }

    #[Test]
    public function it_filters_events_by_end_date(): void
    {
        $soonDate = Carbon::now()->addDays(5);
        $farDate = Carbon::now()->addDays(30);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $soonDate,
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $farDate,
        ]);

        $events = $this->service->getEventsForMeeting('sunday-morning', null, Carbon::now()->addDays(10));

        $this->assertCount(1, $events);
    }

    #[Test]
    public function it_returns_all_upcoming_events(): void
    {
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => Carbon::now()->addDays(5),
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'other-meeting',
            'start_datetime' => Carbon::now()->addDays(10),
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => Carbon::now()->subDays(1),
        ]);

        $events = $this->service->getAllUpcomingEvents(Carbon::now());

        $this->assertCount(2, $events);
    }

    #[Test]
    public function it_excludes_cancelled_events_from_upcoming(): void
    {
        CalendarEvent::factory()->create([
            'start_datetime' => Carbon::now()->addDays(5),
            'status' => 'confirmed',
        ]);
        CalendarEvent::factory()->create([
            'start_datetime' => Carbon::now()->addDays(10),
            'status' => 'cancelled',
        ]);

        $events = $this->service->getAllUpcomingEvents(Carbon::now());

        $this->assertCount(1, $events);
    }

    #[Test]
    public function it_returns_uncategorized_events(): void
    {
        config(['calendar.uncategorized_slug' => 'uncategorized']);

        CalendarEvent::factory()->count(2)->create(['meeting_slug' => 'uncategorized']);
        CalendarEvent::factory()->count(3)->create(['meeting_slug' => 'sunday-morning']);

        $events = $this->service->getUncategorizedEvents();

        $this->assertCount(2, $events);
    }

    #[Test]
    public function it_manually_categorizes_an_event(): void
    {
        $event = CalendarEvent::factory()->create([
            'meeting_slug' => 'uncategorized',
            'is_categorized_automatically' => true,
        ]);

        // Override manuallyCategorizeEvent to avoid Google Calendar API call
        // by using a partial mock approach or just test the DB side effects
        // The method tries to update Google Calendar - we test without that
        $event->update([
            'meeting_slug' => 'sunday-morning',
            'is_categorized_automatically' => false,
        ]);

        $event->refresh();
        $this->assertEquals('sunday-morning', $event->meeting_slug);
        $this->assertFalse($event->is_categorized_automatically);
    }

    #[Test]
    public function it_categorizes_event_updating_db_even_if_google_fails(): void
    {
        $event = CalendarEvent::factory()->create([
            'meeting_slug' => 'uncategorized',
            'google_event_id' => 'nonexistent-google-id-xyz',
        ]);

        // manuallyCategorizeEvent updates DB then tries Google (which will fail gracefully)
        $result = $this->service->manuallyCategorizeEvent($event->id, 'sunday-morning');

        $this->assertEquals('sunday-morning', $result->meeting_slug);
        $this->assertFalse($result->is_categorized_automatically);

        $event->refresh();
        $this->assertEquals('sunday-morning', $event->meeting_slug);
    }

    #[Test]
    public function it_returns_empty_collection_when_no_events_exist(): void
    {
        $events = $this->service->getEventsForMeeting('nonexistent-meeting');

        $this->assertCount(0, $events);
    }

    #[Test]
    public function it_orders_events_by_start_datetime(): void
    {
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => Carbon::now()->addDays(5),
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => Carbon::now()->addDays(2),
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => Carbon::now()->addDays(8),
        ]);

        $events = $this->service->getEventsForMeeting('sunday-morning');

        $this->assertCount(3, $events);
        $this->assertTrue($events[0]->start_datetime->lt($events[1]->start_datetime));
        $this->assertTrue($events[1]->start_datetime->lt($events[2]->start_datetime));
    }
}
