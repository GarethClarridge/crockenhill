<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Data\CalendarCategorizationResult;
use App\Models\CalendarEvent;
use App\Traits\SanitizesLogData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class CalendarService
{
    use SanitizesLogData;

    public function __construct(
        private readonly GoogleCalendarSyncService $googleSync,
    ) {}

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function getUpcomingEventsForMeeting(string $meetingSlug, ?Carbon $from = null): Collection
    {
        return $this->meetingEventsQuery($meetingSlug)
            ->where('start_datetime', '>=', $from ?? now())
            ->orderBy('start_datetime')
            ->get();
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function getRecentPastEventsForMeeting(string $meetingSlug, int $limit = 20, ?Carbon $before = null): Collection
    {
        return $this->meetingEventsQuery($meetingSlug)
            ->where('start_datetime', '<', $before ?? now())
            ->orderByDesc('start_datetime')
            ->limit($limit)
            ->get();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function manuallyCategorizeEvent(int $eventId, string $meetingSlug): CalendarCategorizationResult
    {
        $event = CalendarEvent::query()->findOrFail($eventId);

        $event->update([
            'meeting_slug' => $meetingSlug,
            'is_categorized_automatically' => false,
        ]);

        Log::warning('Calendar event manually categorized', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'event_id' => $event->id,
            'event_title' => $event->title,
            'meeting_slug' => $meetingSlug,
        ]));

        $googleSynced = $this->googleSync->syncCategorizationToGoogle($event->google_event_id, $meetingSlug);

        return new CalendarCategorizationResult($event, $googleSynced);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function manuallyUnCategorizeEvent(int $eventId): CalendarCategorizationResult
    {
        $event = CalendarEvent::query()->findOrFail($eventId);

        $event->update([
            'meeting_slug' => null,
            'is_categorized_automatically' => false,
        ]);

        Log::warning('Calendar event un-categorized', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'event_id' => $event->id,
            'event_title' => $event->title,
        ]));

        $googleSynced = $this->googleSync->removeCategorizationFromGoogle($event->google_event_id);

        return new CalendarCategorizationResult($event, $googleSynced);
    }

    /**
     * @return Builder<CalendarEvent>
     */
    private function meetingEventsQuery(string $meetingSlug): Builder
    {
        return CalendarEvent::query()
            ->forCard()
            ->where('meeting_slug', $meetingSlug)
            ->confirmed();
    }
}
