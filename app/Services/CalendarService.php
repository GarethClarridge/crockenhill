<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class CalendarService
{
    public function __construct(
        private readonly GoogleCalendarSyncService $googleSync,
    ) {}

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function getEventsForMeeting(string $meetingSlug, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $query = CalendarEvent::query()
            ->forCard()
            ->where('meeting_slug', $meetingSlug)
            ->confirmed()
            ->orderBy('start_datetime');

        if ($startDate) {
            $query->where('start_datetime', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('start_datetime', '<=', $endDate);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function getAllUpcomingEvents(?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $query = CalendarEvent::query()
            ->forCard()
            ->confirmed()
            ->where('start_datetime', '>=', $startDate ?? now())
            ->orderBy('start_datetime');

        if ($endDate) {
            $query->where('start_datetime', '<=', $endDate);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function getUncategorizedEvents(): Collection
    {
        return CalendarEvent::query()
            ->forCard()
            ->whereNull('meeting_slug')
            ->confirmed()
            ->orderBy('start_datetime')
            ->get();
    }

    public function manuallyCategorizeEvent(int $eventId, string $meetingSlug): CalendarCategorizationResult
    {
        $event = CalendarEvent::findOrFail($eventId);

        $event->update([
            'meeting_slug' => $meetingSlug,
            'is_categorized_automatically' => false,
        ]);

        Log::warning('Calendar event manually categorized', [
            'admin_id' => auth()->id(),
            'event_id' => $event->id,
            'event_title' => $event->title,
            'meeting_slug' => $meetingSlug,
        ]);

        $googleSynced = $event->google_event_id !== null
            && $this->googleSync->syncCategorizationToGoogle($event->google_event_id, $meetingSlug);

        return new CalendarCategorizationResult($event, $googleSynced);
    }

    public function manuallyUnCategorizeEvent(int $eventId): CalendarCategorizationResult
    {
        $event = CalendarEvent::findOrFail($eventId);

        $event->update([
            'meeting_slug' => null,
            'is_categorized_automatically' => false,
        ]);

        Log::warning('Calendar event un-categorized', [
            'admin_id' => auth()->id(),
            'event_id' => $event->id,
            'event_title' => $event->title,
        ]);

        $googleSynced = $event->google_event_id !== null
            && $this->googleSync->removeCategorizationFromGoogle($event->google_event_id);

        return new CalendarCategorizationResult($event, $googleSynced);
    }
}
