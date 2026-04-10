<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

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
        /**
         * Performance Optimization: Limits retrieved columns to required fields for cards
         * to reduce memory usage and DB I/O.
         */
        $query = CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
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
        /**
         * Performance Optimization: Limits retrieved columns to required fields for cards
         * to reduce memory usage and DB I/O.
         */
        $query = CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
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
        /**
         * Performance Optimization: Limits retrieved columns to required fields for cards
         * to reduce memory usage and DB I/O.
         */
        return CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
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

        $googleSynced = $event->google_event_id !== null
            && $this->googleSync->syncCategorizationToGoogle($event->google_event_id, $meetingSlug);

        return new CalendarCategorizationResult($event, $googleSynced);
    }
}
