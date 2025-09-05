<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\GoogleCalendar\Event;

class CalendarService
{
    public function getEventsForMeeting(string $meetingSlug, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $query = CalendarEvent::where('meeting_slug', $meetingSlug)
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

    public function getAllUpcomingEvents(?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $query = CalendarEvent::where('status', '!=', 'cancelled')
            ->where('start_datetime', '>=', $startDate ?? now())
            ->orderBy('start_datetime');

        if ($endDate) {
            $query->where('start_datetime', '<=', $endDate);
        }

        return $query->get();
    }

    public function syncFromGoogleCalendar(): array
    {
        $startDate = now()->subMonths(config('calendar.sync_window.past_months', 3));
        $endDate = now()->addYears(config('calendar.sync_window.future_years', 2));

        try {
            $googleEvents = Event::get(
                $startDate,
                $endDate,
                [
                    'singleEvents' => true,
                    'orderBy' => 'startTime',
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch events from Google Calendar', ['error' => $e->getMessage()]);
            throw $e;
        }

        $existingEventIds = CalendarEvent::whereBetween('start_datetime', [$startDate, $endDate])
            ->pluck('google_event_id')
            ->toArray();
        $processedEventIds = [];

        foreach ($googleEvents as $googleEvent) {
            try {
                $this->syncSingleEvent($googleEvent);
                $processedEventIds[] = $googleEvent->id;
            } catch (\Exception $e) {
                Log::warning('Failed to sync single event', [
                    'event_id' => $googleEvent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $deletedEventIds = array_diff($existingEventIds, $processedEventIds);
        CalendarEvent::whereIn('google_event_id', $deletedEventIds)->delete();

        $uncategorizedCount = CalendarEvent::whereBetween('start_datetime', [$startDate, $endDate])
            ->where('meeting_slug', config('calendar.uncategorized_slug', 'uncategorized'))
            ->count();

        Log::info('Google Calendar sync completed', [
            'processed_events' => count($processedEventIds),
            'deleted_events' => count($deletedEventIds),
            'uncategorized_events' => $uncategorizedCount,
            'sync_window' => [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')],
        ]);

        return [
            'processed_events' => count($processedEventIds),
            'deleted_events' => count($deletedEventIds),
            'uncategorized_events' => $uncategorizedCount,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ];
    }

    public function syncSingleEvent(Event $googleEvent): CalendarEvent
    {
        $meetingSlug = $this->determineMeetingSlug($googleEvent);

        // Access extended properties from the underlying Google Calendar event
        $extendedProperties = $googleEvent->googleEvent->getExtendedProperties();
        $speaker = null;
        $hasManualSlug = false;

        /** @phpstan-ignore-next-line */
        if ($extendedProperties) {
            $speaker = $extendedProperties['private']['speaker_name'] ?? null;
            $hasManualSlug = isset($extendedProperties['private']['meeting_slug']);
        }

        $calendarEvent = CalendarEvent::updateOrCreate(
            /** @phpstan-ignore-next-line */
            ['google_event_id' => $googleEvent->id],
            [
                'meeting_slug' => $meetingSlug,
                /** @phpstan-ignore-next-line */
                'title' => $googleEvent->name,
                /** @phpstan-ignore-next-line */
                'description' => $googleEvent->description,
                'speaker' => $speaker,
                /** @phpstan-ignore-next-line */
                'location' => $googleEvent->location,
                /** @phpstan-ignore-next-line */
                'start_datetime' => $googleEvent->startDateTime,
                /** @phpstan-ignore-next-line */
                'end_datetime' => $googleEvent->endDateTime,
                'status' => $googleEvent->status ?? 'confirmed',
                'is_categorized_automatically' => ! $hasManualSlug,
            ]
        );

        return $calendarEvent;
    }

    public function createEventForMeeting(string $meetingSlug, array $eventData): Event
    {
        $meeting = Meeting::where('slug', $meetingSlug)->firstOrFail();

        $event = new Event;
        /** @phpstan-ignore-next-line */
        $event->name = $eventData['title'];
        /** @phpstan-ignore-next-line */
        $event->startDateTime = Carbon::parse($eventData['start_datetime']);
        /** @phpstan-ignore-next-line */
        $event->endDateTime = Carbon::parse($eventData['end_datetime']);
        /** @phpstan-ignore-next-line */
        $event->location = $eventData['location'] ?? $meeting->location;
        /** @phpstan-ignore-next-line */
        $event->description = $eventData['description'] ?? '';

        // Set extended properties on the underlying Google Calendar event
        $extendedProperties = [
            'private' => [
                'meeting_slug' => $meetingSlug,
                'speaker_name' => $eventData['speaker'] ?? '',
            ],
        ];
        /** @phpstan-ignore-next-line */
        $event->googleEvent->setExtendedProperties($extendedProperties);

        $event->save();

        $this->syncSingleEvent($event);

        return $event;
    }

    private function determineMeetingSlug(Event $googleEvent): string
    {
        // Access extended properties from the underlying Google Calendar event
        $extendedProperties = $googleEvent->googleEvent->getExtendedProperties();
        $extendedSlug = null;

        /** @phpstan-ignore-next-line */
        if ($extendedProperties && isset($extendedProperties['private']['meeting_slug'])) {
            $extendedSlug = $extendedProperties['private']['meeting_slug'];
        }

        if ($extendedSlug) {
            return $extendedSlug;
        }

        /** @phpstan-ignore-next-line */
        $title = strtolower($googleEvent->name);
        $patterns = config('calendar.meeting_patterns');

        foreach ($patterns as $meetingSlug => $config) {
            foreach ($config['patterns'] as $pattern) {
                $searchPattern = $config['case_insensitive'] ? strtolower($pattern) : $pattern;
                if (str_contains($title, $searchPattern)) {
                    return $meetingSlug;
                }
            }
        }

        return config('calendar.uncategorized_slug', 'uncategorized');
    }

    public function getUncategorizedEvents(): Collection
    {
        return CalendarEvent::where('meeting_slug', config('calendar.uncategorized_slug', 'uncategorized'))
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

    public function getCachedEventsForMeeting(string $meetingSlug, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $cacheKey = "meeting_events_{$meetingSlug}_".($startDate?->format('Y-m-d') ?? 'all').'_'.($endDate?->format('Y-m-d') ?? 'all');

        return Cache::remember($cacheKey, now()->addHours(1), function () use ($meetingSlug, $startDate, $endDate) {
            return $this->getEventsForMeeting($meetingSlug, $startDate, $endDate);
        });
    }

    public function clearEventCache(?string $meetingSlug = null): void
    {
        if ($meetingSlug) {
            Cache::forget("meeting_events_{$meetingSlug}_*");
        } else {
            Cache::flush();
        }
    }
}
