<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CalendarEvent;
use App\Services\CalendarService;
use Illuminate\Support\Facades\Log;

class CategorizeCalendarEvent
{
    public function __construct(private readonly CalendarService $calendarService) {}

    public function execute(CalendarEvent $event, ?string $meetingSlug): void
    {
        if ($meetingSlug === null) {
            $event->update([
                'meeting_slug' => null,
                'is_categorized_automatically' => false,
            ]);

            Log::warning('Calendar event un-categorized', [
                'admin_id' => auth()->id(),
                'event_id' => $event->id,
                'event_title' => $event->title,
            ]);

            return;
        }

        $this->calendarService->manuallyCategorizeEvent($event->id, $meetingSlug);
    }
}
