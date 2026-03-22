<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Meetings;

use App\Actions\Meetings\CreateMeetingCalendarEvent;
use App\Models\Meeting;
use App\Services\GoogleCalendarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\GoogleCalendar\Event;
use Tests\TestCase;

class CreateMeetingCalendarEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_delegates_google_calendar_event_creation_through_the_action(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'midweek-prayer',
        ]);

        $eventData = [
            'title' => 'Midweek Prayer',
            'start_datetime' => '2026-03-25 19:30:00',
            'end_datetime' => '2026-03-25 20:30:00',
            'speaker' => 'Guest Speaker',
        ];

        $event = new Event;

        $syncService = $this->createMock(GoogleCalendarSyncService::class);
        $syncService->expects($this->once())
            ->method('createEventForMeeting')
            ->with('midweek-prayer', $eventData)
            ->willReturn($event);

        $action = new CreateMeetingCalendarEvent($syncService);

        $this->assertSame($event, $action->execute($meeting, $eventData));
    }
}
