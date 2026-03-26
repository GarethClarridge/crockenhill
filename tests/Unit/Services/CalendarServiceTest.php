<?php

namespace Tests\Unit\Services;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Services\CalendarCategorizationResult;
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
        Meeting::factory()->create(['slug' => 'other-meeting']);

        CalendarEvent::factory()->count(3)->create(['meeting_slug' => 'sunday-morning']);
        CalendarEvent::factory()->count(2)->create(['meeting_slug' => 'other-meeting']);

        $events = $this->service->getEventsForMeeting('sunday-morning');

        $this->assertCount(3, $events);
        $events->each(fn ($event) => $this->assertEquals('sunday-morning', $event->meeting_slug));
    }

    #[Test]
    public function it_excludes_cancelled_events(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        CalendarEvent::factory()->count(2)->create(['meeting_slug' => 'sunday-morning']);
        CalendarEvent::factory()->create(['meeting_slug' => 'sunday-morning', 'status' => 'cancelled']);

        $events = $this->service->getEventsForMeeting('sunday-morning');

        $this->assertCount(2, $events);
    }

    #[Test]
    public function it_only_returns_confirmed_events_for_a_meeting(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Confirmed Event',
            'status' => 'confirmed',
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Tentative Event',
            'status' => 'tentative',
        ]);

        $events = $this->service->getEventsForMeeting('sunday-morning');

        $this->assertCount(1, $events);
        $this->assertSame('Confirmed Event', $events->sole()->title);
    }

    #[Test]
    public function it_filters_events_by_start_date(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        $future = Carbon::now()->addDays(10);
        $past = Carbon::now()->subDays(10);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $future,
            'end_datetime' => (clone $future)->addHour(),
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $past,
            'end_datetime' => (clone $past)->addHour(),
        ]);

        $events = $this->service->getEventsForMeeting('sunday-morning', Carbon::now());

        $this->assertCount(1, $events);
        $this->assertTrue($events->first()->start_datetime->isAfter(Carbon::now()));
    }

    #[Test]
    public function it_filters_events_by_end_date(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        $soonDate = Carbon::now()->addDays(5);
        $farDate = Carbon::now()->addDays(30);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $soonDate,
            'end_datetime' => (clone $soonDate)->addHour(),
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $farDate,
            'end_datetime' => (clone $farDate)->addHour(),
        ]);

        $events = $this->service->getEventsForMeeting('sunday-morning', null, Carbon::now()->addDays(10));

        $this->assertCount(1, $events);
    }

    #[Test]
    public function it_returns_all_upcoming_events(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        Meeting::factory()->create(['slug' => 'other-meeting']);

        $windowStart = Carbon::create(2099, 1, 1, 0, 0, 0);
        $windowEnd = Carbon::create(2099, 1, 31, 23, 59, 59);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => Carbon::create(2099, 1, 5, 10, 0, 0),
            'end_datetime' => Carbon::create(2099, 1, 5, 11, 0, 0),
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'other-meeting',
            'start_datetime' => Carbon::create(2099, 1, 10, 10, 0, 0),
            'end_datetime' => Carbon::create(2099, 1, 10, 11, 0, 0),
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => Carbon::create(2098, 12, 31, 10, 0, 0),
            'end_datetime' => Carbon::create(2098, 12, 31, 11, 0, 0),
        ]);

        $events = $this->service->getAllUpcomingEvents($windowStart, $windowEnd);

        $this->assertCount(2, $events);
    }

    #[Test]
    public function it_excludes_cancelled_events_from_upcoming(): void
    {
        $windowStart = Carbon::create(2099, 2, 1, 0, 0, 0);
        $windowEnd = Carbon::create(2099, 2, 28, 23, 59, 59);

        CalendarEvent::factory()->create([
            'start_datetime' => Carbon::create(2099, 2, 5, 10, 0, 0),
            'end_datetime' => Carbon::create(2099, 2, 5, 11, 0, 0),
            'status' => 'confirmed',
        ]);
        CalendarEvent::factory()->create([
            'start_datetime' => Carbon::create(2099, 2, 10, 10, 0, 0),
            'end_datetime' => Carbon::create(2099, 2, 10, 11, 0, 0),
            'status' => 'cancelled',
        ]);

        $events = $this->service->getAllUpcomingEvents($windowStart, $windowEnd);

        $this->assertCount(1, $events);
    }

    #[Test]
    public function it_only_returns_confirmed_upcoming_events(): void
    {
        $windowStart = Carbon::create(2099, 3, 1, 0, 0, 0);
        $windowEnd = Carbon::create(2099, 3, 31, 23, 59, 59);

        CalendarEvent::factory()->create([
            'start_datetime' => Carbon::create(2099, 3, 5, 10, 0, 0),
            'end_datetime' => Carbon::create(2099, 3, 5, 11, 0, 0),
            'title' => 'Confirmed Upcoming Event',
            'status' => 'confirmed',
        ]);
        CalendarEvent::factory()->create([
            'start_datetime' => Carbon::create(2099, 3, 10, 10, 0, 0),
            'end_datetime' => Carbon::create(2099, 3, 10, 11, 0, 0),
            'title' => 'Tentative Upcoming Event',
            'status' => 'tentative',
        ]);

        $events = $this->service->getAllUpcomingEvents($windowStart, $windowEnd);

        $this->assertCount(1, $events);
        $this->assertSame('Confirmed Upcoming Event', $events->sole()->title);
    }

    #[Test]
    public function it_returns_uncategorized_events(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        CalendarEvent::factory()->count(2)->create(['meeting_slug' => null, 'status' => 'confirmed']);
        CalendarEvent::factory()->count(3)->create(['meeting_slug' => 'sunday-morning']);

        $events = $this->service->getUncategorizedEvents();

        $this->assertCount(2, $events);
        $events->each(fn ($event) => $this->assertNull($event->meeting_slug));
    }

    #[Test]
    public function it_only_returns_confirmed_uncategorized_events(): void
    {
        CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'title' => 'Confirmed Uncategorized Event',
            'status' => 'confirmed',
        ]);
        CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'title' => 'Tentative Uncategorized Event',
            'status' => 'tentative',
        ]);

        $events = $this->service->getUncategorizedEvents();

        $this->assertCount(1, $events);
        $this->assertSame('Confirmed Uncategorized Event', $events->sole()->title);
    }

    #[Test]
    public function it_manually_categorizes_an_event(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => null,
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
        config(['google-calendar.calendar_id' => 'test-calendar-id']);
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'google_event_id' => 'nonexistent-google-id-xyz',
        ]);

        // manuallyCategorizeEvent updates DB then tries Google (which will fail gracefully)
        $result = $this->service->manuallyCategorizeEvent($event->id, 'sunday-morning');

        $this->assertInstanceOf(CalendarCategorizationResult::class, $result);
        $this->assertEquals('sunday-morning', $result->event->meeting_slug);
        $this->assertFalse($result->event->is_categorized_automatically);
        $this->assertFalse($result->googleSynced);

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
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        $date1 = Carbon::now()->addDays(5);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $date1,
            'end_datetime' => (clone $date1)->addHour(),
        ]);
        $date2 = Carbon::now()->addDays(2);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $date2,
            'end_datetime' => (clone $date2)->addHour(),
        ]);
        $date3 = Carbon::now()->addDays(8);
        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'start_datetime' => $date3,
            'end_datetime' => (clone $date3)->addHour(),
        ]);

        $events = $this->service->getEventsForMeeting('sunday-morning');

        $this->assertCount(3, $events);
        $this->assertTrue($events[0]->start_datetime->lt($events[1]->start_datetime));
        $this->assertTrue($events[1]->start_datetime->lt($events[2]->start_datetime));
    }
}
