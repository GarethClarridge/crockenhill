<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CalendarEvent;
use App\Services\Public\PublicMeetingReadModelCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class CalendarEventObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
    ) {}

    public function created(CalendarEvent $calendarEvent): void
    {
        $this->clearCaches($calendarEvent);
    }

    public function deleted(CalendarEvent $calendarEvent): void
    {
        $this->clearCaches($calendarEvent);
    }

    public function updated(CalendarEvent $calendarEvent): void
    {
        $this->clearCaches($calendarEvent);
        $this->publicMeetingReadModelCache->forgetBySlug($calendarEvent->getOriginal('meeting_slug'));
    }

    private function clearCaches(CalendarEvent $calendarEvent): void
    {
        $this->publicMeetingReadModelCache->forgetBySlug($calendarEvent->meeting_slug);
    }
}
