<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\CalendarCategorizationResult;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\GoogleCalendarSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class CalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    private CalendarService $service;

    /** @var MockObject&GoogleCalendarSyncService */
    private MockObject $googleSync;

    protected function setUp(): void
    {
        parent::setUp();
        config(['google-calendar.calendar_id' => 'test-calendar-id']);
        $this->googleSync = $this->createMock(GoogleCalendarSyncService::class);
        $this->service = new CalendarService($this->googleSync);
    }

    #[Test]
    public function it_returns_upcoming_events_for_a_meeting_ordered_ascending(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        Meeting::factory()->create(['slug' => 'other-meeting']);

        $now = Carbon::create(2026, 5, 12, 12, 0, 0);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Later Upcoming Event',
            'start_datetime' => $now->copy()->addDays(5),
            'end_datetime' => $now->copy()->addDays(5)->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Soon Upcoming Event',
            'start_datetime' => $now->copy()->addDay(),
            'end_datetime' => $now->copy()->addDay()->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Past Event',
            'start_datetime' => $now->copy()->subDay(),
            'end_datetime' => $now->copy()->subDay()->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'other-meeting',
            'title' => 'Other Meeting Event',
            'start_datetime' => $now->copy()->addDay(),
            'end_datetime' => $now->copy()->addDay()->addHour(),
        ]);

        $events = $this->service->getUpcomingEventsForMeeting('sunday-morning', $now);

        $this->assertSame(['Soon Upcoming Event', 'Later Upcoming Event'], $events->pluck('title')->all());
    }

    #[Test]
    public function it_returns_recent_past_events_for_a_meeting_with_an_explicit_limit(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);
        Meeting::factory()->create(['slug' => 'other-meeting']);

        $now = Carbon::create(2026, 5, 12, 12, 0, 0);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Newest Past Event',
            'start_datetime' => $now->copy()->subDay(),
            'end_datetime' => $now->copy()->subDay()->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Second Past Event',
            'start_datetime' => $now->copy()->subDays(2),
            'end_datetime' => $now->copy()->subDays(2)->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Older Past Event',
            'start_datetime' => $now->copy()->subDays(3),
            'end_datetime' => $now->copy()->subDays(3)->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'title' => 'Upcoming Event',
            'start_datetime' => $now->copy()->addDay(),
            'end_datetime' => $now->copy()->addDay()->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => 'other-meeting',
            'title' => 'Other Meeting Past Event',
            'start_datetime' => $now->copy()->subDay(),
            'end_datetime' => $now->copy()->subDay()->addHour(),
        ]);

        $events = $this->service->getRecentPastEventsForMeeting('sunday-morning', 2, $now);

        $this->assertSame(['Newest Past Event', 'Second Past Event'], $events->pluck('title')->all());
    }

    #[Test]
    public function it_manually_categorizes_an_event(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'is_categorized_automatically' => true,
            'google_event_id' => 'google-event-abc',
        ]);

        $this->googleSync
            ->expects($this->once())
            ->method('syncCategorizationToGoogle')
            ->with('google-event-abc', 'sunday-morning')
            ->willReturn(true);

        $result = $this->service->manuallyCategorizeEvent($event->id, 'sunday-morning');

        $this->assertInstanceOf(CalendarCategorizationResult::class, $result);
        $this->assertEquals('sunday-morning', $result->event->meeting_slug);
        $this->assertFalse($result->event->is_categorized_automatically);
        $this->assertTrue($result->googleSynced);

        $event->refresh();
        $this->assertEquals('sunday-morning', $event->meeting_slug);
        $this->assertFalse($event->is_categorized_automatically);
    }

    #[Test]
    public function it_categorizes_event_updating_db_even_if_google_fails(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'google_event_id' => 'google-event-xyz',
        ]);

        $this->googleSync
            ->method('syncCategorizationToGoogle')
            ->willReturn(false);

        $result = $this->service->manuallyCategorizeEvent($event->id, 'sunday-morning');

        $this->assertInstanceOf(CalendarCategorizationResult::class, $result);
        $this->assertEquals('sunday-morning', $result->event->meeting_slug);
        $this->assertFalse($result->event->is_categorized_automatically);
        $this->assertFalse($result->googleSynced);

        $event->refresh();
        $this->assertEquals('sunday-morning', $event->meeting_slug);
    }

    #[Test]
    public function it_manually_uncategorizes_an_event(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'is_categorized_automatically' => true,
            'google_event_id' => 'google-event-abc',
        ]);

        $this->googleSync
            ->expects($this->once())
            ->method('removeCategorizationFromGoogle')
            ->with('google-event-abc')
            ->willReturn(true);

        $result = $this->service->manuallyUnCategorizeEvent($event->id);

        $this->assertInstanceOf(CalendarCategorizationResult::class, $result);
        $this->assertNull($result->event->meeting_slug);
        $this->assertFalse($result->event->is_categorized_automatically);
        $this->assertTrue($result->googleSynced);

        $event->refresh();
        $this->assertNull($event->meeting_slug);
        $this->assertFalse($event->is_categorized_automatically);
    }

    #[Test]
    public function it_uncategorizes_event_updating_db_even_if_google_fails(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-morning']);

        $event = CalendarEvent::factory()->create([
            'meeting_slug' => 'sunday-morning',
            'google_event_id' => 'google-event-xyz',
        ]);

        $this->googleSync
            ->method('removeCategorizationFromGoogle')
            ->willReturn(false);

        $result = $this->service->manuallyUnCategorizeEvent($event->id);

        $this->assertInstanceOf(CalendarCategorizationResult::class, $result);
        $this->assertNull($result->event->meeting_slug);
        $this->assertFalse($result->googleSynced);

        $event->refresh();
        $this->assertNull($event->meeting_slug);
    }
}
