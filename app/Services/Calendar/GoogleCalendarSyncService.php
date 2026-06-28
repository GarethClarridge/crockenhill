<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Traits\SanitizesLogData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\GoogleCalendar\Event;

class GoogleCalendarSyncService
{
    use SanitizesLogData;

    /** @var array<int, string>|null */
    private ?array $knownMeetingSlugs = null;

    /**
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function syncFromGoogleCalendar(): array
    {
        $startDate = now()->subMonths(config('calendar.sync_window.past_months', 3));
        $endDate = now()->addYears(config('calendar.sync_window.future_years', 2));

        try {
            $googleEvents = $this->fetchEventsFromGoogle($startDate, $endDate);
        } catch (\Exception $e) {
            Log::error('Failed to fetch events from Google Calendar', $this->sanitizeArrayForLog([
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }

        $existingEventIds = CalendarEvent::query()->whereBetween('start_datetime', [$startDate, $endDate])
            ->pluck('google_event_id')
            ->toArray();

        // Track seen and processed separately: an event that Google returned but failed
        // to process must NOT be deleted — only events absent from Google entirely should be.
        $seenUpstreamIds = [];
        $processedEventIds = [];

        foreach ($googleEvents as $googleEvent) {
            /** @phpstan-ignore-next-line */
            $seenUpstreamIds[] = $googleEvent->id;
            try {
                $this->syncSingleEvent($googleEvent);
                /** @phpstan-ignore-next-line */
                $processedEventIds[] = $googleEvent->id;
            } catch (\Exception $e) {
                Log::warning('Failed to sync single event', $this->sanitizeArrayForLog([
                    /** @phpstan-ignore-next-line */
                    'event_id' => (string) $googleEvent->id,
                    'error' => $e->getMessage(),
                ]));
            }
        }

        $deletedEventIds = array_diff($existingEventIds, $seenUpstreamIds);
        CalendarEvent::query()->whereIn('google_event_id', $deletedEventIds)->delete();

        $uncategorizedCount = CalendarEvent::query()->whereBetween('start_datetime', [$startDate, $endDate])
            ->whereNull('meeting_slug')
            ->count();

        $skippedEventIds = array_diff($seenUpstreamIds, $processedEventIds);

        Log::info('Google Calendar sync completed', $this->sanitizeArrayForLog([
            'processed_events' => count($processedEventIds),
            'skipped_events' => count($skippedEventIds),
            'deleted_events' => count($deletedEventIds),
            'uncategorized_events' => $uncategorizedCount,
            'sync_window' => [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')],
        ]));

        return [
            'processed_events' => count($processedEventIds),
            'skipped_events' => count($skippedEventIds),
            'deleted_events' => count($deletedEventIds),
            'uncategorized_events' => $uncategorizedCount,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ];
    }

    /**
     * Fetch events from the Google Calendar API for the given window.
     *
     * Extracted as a protected method so tests can substitute a known event list
     * without requiring a live Google Calendar connection.
     *
     * @return Collection<int, Event>
     */
    protected function fetchEventsFromGoogle(Carbon $startDate, Carbon $endDate): Collection
    {
        return Event::get(
            $startDate,
            $endDate,
            [
                'singleEvents' => true,
                'orderBy' => 'startTime',
            ]
        );
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

        $calendarEvent = CalendarEvent::query()->updateOrCreate(
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

    /**
     * Push a manual meeting_slug categorization to the Google Calendar event's extended properties.
     *
     * Returns true when the Google write succeeded, false when it failed gracefully.
     */
    public function syncCategorizationToGoogle(string $googleEventId, string $meetingSlug): bool
    {
        try {
            $googleEvent = Event::find($googleEventId);
            /** @phpstan-ignore-next-line */
            if (! $googleEvent) {
                return false;
            }

            /** @phpstan-ignore-next-line */
            $extendedProperties = $googleEvent->googleEvent->getExtendedProperties() ?? [];
            if (! isset($extendedProperties['private'])) {
                $extendedProperties['private'] = [];
            }
            $extendedProperties['private']['meeting_slug'] = $meetingSlug;

            $googleEvent->googleEvent->setExtendedProperties($extendedProperties);
            $googleEvent->save();

            return true;
        } catch (\Exception $e) {
            Log::warning('Failed to update Google Calendar extended property', $this->sanitizeArrayForLog([
                'google_event_id' => $googleEventId,
                'error' => $e->getMessage(),
            ]));

            return false;
        }
    }

    /**
     * Clear a manual meeting_slug categorization from the Google Calendar event's extended properties.
     *
     * Returns true when the Google write succeeded, false when it failed gracefully.
     */
    public function removeCategorizationFromGoogle(string $googleEventId): bool
    {
        try {
            $googleEvent = Event::find($googleEventId);
            /** @phpstan-ignore-next-line */
            if (! $googleEvent) {
                return false;
            }

            /** @phpstan-ignore-next-line */
            $extendedProperties = $googleEvent->googleEvent->getExtendedProperties() ?? [];
            if (isset($extendedProperties['private']['meeting_slug'])) {
                unset($extendedProperties['private']['meeting_slug']);
            }

            $googleEvent->googleEvent->setExtendedProperties($extendedProperties);
            $googleEvent->save();

            return true;
        } catch (\Exception $e) {
            Log::warning('Failed to clear Google Calendar extended property', $this->sanitizeArrayForLog([
                'google_event_id' => $googleEventId,
                'error' => $e->getMessage(),
            ]));

            return false;
        }
    }

    private function determineMeetingSlug(Event $googleEvent): ?string
    {
        // Access extended properties from the underlying Google Calendar event
        $extendedProperties = $googleEvent->googleEvent->getExtendedProperties();
        $extendedSlug = null;

        /** @phpstan-ignore-next-line */
        if ($extendedProperties && isset($extendedProperties['private']['meeting_slug'])) {
            $extendedSlug = $extendedProperties['private']['meeting_slug'];
        }

        if (is_string($extendedSlug) && $this->isKnownMeetingSlug($extendedSlug)) {
            return $extendedSlug;
        }

        /** @phpstan-ignore-next-line */
        $title = strtolower($googleEvent->name);
        $patterns = config('calendar.meeting_patterns');

        foreach ($patterns as $meetingSlug => $config) {
            foreach ($config['patterns'] as $pattern) {
                $searchPattern = $config['case_insensitive'] ? strtolower($pattern) : $pattern;
                if (str_contains($title, $searchPattern)) {
                    return $this->isKnownMeetingSlug($meetingSlug) ? $meetingSlug : null;
                }
            }
        }

        return null;
    }

    private function isKnownMeetingSlug(string $slug): bool
    {
        if ($this->knownMeetingSlugs === null) {
            $this->knownMeetingSlugs = Meeting::query()
                ->orderBy('slug')
                ->pluck('slug')
                ->all();
        }

        return in_array($slug, $this->knownMeetingSlugs, true);
    }
}
