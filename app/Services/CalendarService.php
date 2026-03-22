<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\GoogleCalendar\Event;

class CalendarService
{
    /**
     * @return Collection<int, CalendarEvent>
     */
    public function getEventsForMeeting(string $meetingSlug, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        /**
         * Performance Optimization: Limits retrieved columns to required fields for cards
         * to reduce memory usage and DB I/O.
         */
        $query = CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
            ->where('meeting_slug', $meetingSlug)
            ->where('status', '!=', 'cancelled')
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
        /**
         * Performance Optimization: Limits retrieved columns to required fields for cards
         * to reduce memory usage and DB I/O.
         */
        $query = CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
            ->where('status', '!=', 'cancelled')
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
        /**
         * Performance Optimization: Limits retrieved columns to required fields for cards
         * to reduce memory usage and DB I/O.
         */
        return CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
            ->whereNull('meeting_slug')
            ->orderBy('start_datetime')
            ->get();
    }

    public function manuallyCategorizeEvent(int $eventId, string $meetingSlug): CalendarEvent
    {
        $event = CalendarEvent::findOrFail($eventId);

        $event->update([
            'meeting_slug' => $meetingSlug,
            'is_categorized_automatically' => false,
        ]);

        try {
            $googleEvent = Event::find($event->google_event_id);
            /** @phpstan-ignore-next-line */
            if ($googleEvent) {
                // Get existing extended properties and update them
                /** @phpstan-ignore-next-line */
                $extendedProperties = $googleEvent->googleEvent->getExtendedProperties() ?? [];
                if (! isset($extendedProperties['private'])) {
                    $extendedProperties['private'] = [];
                }
                $extendedProperties['private']['meeting_slug'] = $meetingSlug;

                $googleEvent->googleEvent->setExtendedProperties($extendedProperties);
                $googleEvent->save();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to update Google Calendar extended property', [
                'event_id' => $event->id,
                'google_event_id' => $event->google_event_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $event;
    }
}
