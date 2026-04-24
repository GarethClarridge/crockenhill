<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CalendarEvent;
use App\Services\CalendarService;

class CategorizeCalendarEvent
{
    public function __construct(private readonly CalendarService $calendarService) {}

    public function execute(CalendarEvent $event, ?string $meetingSlug): void
    {
        if ($meetingSlug === null) {
            $this->calendarService->manuallyUnCategorizeEvent($event->id);

            return;
        }

        $this->calendarService->manuallyCategorizeEvent($event->id, $meetingSlug);
    }
}
