<?php

declare(strict_types=1);

namespace App\Actions\Meetings;

use App\Models\Meeting;
use App\Services\GoogleCalendarSyncService;
use Spatie\GoogleCalendar\Event;

class CreateMeetingCalendarEvent
{
    public function __construct(
        private readonly GoogleCalendarSyncService $googleCalendarSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $eventData
     */
    public function execute(Meeting $meeting, array $eventData): Event
    {
        return $this->googleCalendarSyncService->createEventForMeeting($meeting->slug, $eventData);
    }
}
