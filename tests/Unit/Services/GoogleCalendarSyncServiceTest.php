<?php

namespace Tests\Unit\Services;

use App\Services\GoogleCalendarSyncService;
use Carbon\Carbon;
use Google\Service\Calendar\EventExtendedProperties;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
