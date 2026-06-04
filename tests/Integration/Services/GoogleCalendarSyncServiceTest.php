<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\CalendarEvent;
use App\Services\Calendar\GoogleCalendarSyncService;
use Carbon\Carbon;
use Google\Service\Calendar\EventExtendedProperties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\GoogleCalendar\Event;
use Tests\TestCase;

class GoogleCalendarSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private GoogleCalendarSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GoogleCalendarSyncService;
    }

    #[Test]
    public function it_syncs_one_off_events_as_uncategorized_when_no_meeting_matches(): void
    {
        $event = $this->makeGoogleEvent(
            id: 'one-off-event',
            name: 'Church Picnic',
        );

        $calendarEvent = $this->service->syncSingleEvent($event);

        $this->assertNull($calendarEvent->meeting_slug);
        $this->assertTrue($calendarEvent->is_categorized_automatically);
    }

    #[Test]
    public function it_ignores_stale_manual_meeting_slugs_that_no_longer_exist(): void
    {
        $event = $this->makeGoogleEvent(
            id: 'stale-manual-event',
            name: 'Church Picnic With Old Tag',
            extendedMeetingSlug: 'deleted-meeting',
        );

        $calendarEvent = $this->service->syncSingleEvent($event);

        $this->assertNull($calendarEvent->meeting_slug);
        $this->assertFalse($calendarEvent->is_categorized_automatically);
    }

    #[Test]
    public function it_does_not_delete_local_event_when_single_event_fails_to_process(): void
    {
        // A local event that Google returned but whose processing threw an exception.
        // Before the fix, this event would be deleted because it was never added to
        // $processedEventIds. After the fix, $seenUpstreamIds protects it.
        CalendarEvent::factory()->create([
            'google_event_id' => 'event-that-fails',
            'start_datetime' => now()->addDays(7),
            'end_datetime' => now()->addDays(7)->addHour(),
        ]);

        $fakeGoogleEvent = $this->makeGoogleEvent(id: 'event-that-fails', name: 'Failing Event');

        /** @var GoogleCalendarSyncService&Mockery\MockInterface $service */
        $service = Mockery::mock(GoogleCalendarSyncService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('fetchEventsFromGoogle')
            ->once()
            ->andReturn(collect([$fakeGoogleEvent]));

        $service->shouldReceive('syncSingleEvent')
            ->with($fakeGoogleEvent)
            ->andThrow(new \Exception('Simulated processing failure'));

        $service->syncFromGoogleCalendar();

        $this->assertDatabaseHas('calendar_events', ['google_event_id' => 'event-that-fails']);
    }

    #[Test]
    public function it_deletes_events_genuinely_removed_from_google(): void
    {
        // A local event that Google no longer returns — it was truly deleted upstream.
        CalendarEvent::factory()->create([
            'google_event_id' => 'removed-from-google',
            'start_datetime' => now()->addDays(7),
            'end_datetime' => now()->addDays(7)->addHour(),
        ]);

        /** @var GoogleCalendarSyncService&Mockery\MockInterface $service */
        $service = Mockery::mock(GoogleCalendarSyncService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('fetchEventsFromGoogle')
            ->once()
            ->andReturn(collect([]));

        $service->syncFromGoogleCalendar();

        $this->assertDatabaseMissing('calendar_events', ['google_event_id' => 'removed-from-google']);
    }

    #[Test]
    public function it_returns_false_when_google_event_cannot_be_found_during_categorization_sync(): void
    {
        config(['google-calendar.calendar_id' => 'test-calendar-id']);

        $result = $this->service->syncCategorizationToGoogle('nonexistent-google-event-id', 'sunday-morning');

        $this->assertFalse($result);
    }

    private function makeGoogleEvent(string $id, string $name, ?string $extendedMeetingSlug = null): Event
    {
        $event = new Event;
        $event->id = $id;
        $event->name = $name;
        $event->description = 'Calendar event description';
        $event->location = 'Church hall';
        $event->startDateTime = Carbon::create(2026, 3, 15, 10, 0, 0);
        $event->endDateTime = Carbon::create(2026, 3, 15, 11, 0, 0);
        $event->status = 'confirmed';

        if ($extendedMeetingSlug !== null) {
            $properties = new EventExtendedProperties;
            $properties->setPrivate(['meeting_slug' => $extendedMeetingSlug]);
            $event->googleEvent->setExtendedProperties($properties);
        }

        return $event;
    }
}
