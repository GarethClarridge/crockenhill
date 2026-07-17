<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Services\Public\PublicMeetingReadModelCache;
use App\Traits\SanitizesLogData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\GoogleCalendar\Event;

class GoogleCalendarSyncService
{
    use SanitizesLogData;

    /** @var array<int, string>|null */
    private ?array $knownMeetingSlugs = null;

    public function __construct(
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
    ) {}

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

        // A mass delete dispatches no per-model events, so CalendarEventObserver
        // never sees the removals — capture the affected meetings first and forget
        // their public read models explicitly.
        $affectedMeetingSlugs = CalendarEvent::query()
            ->whereIn('google_event_id', $deletedEventIds)
            ->whereNotNull('meeting_slug')
            ->distinct()
            ->pluck('meeting_slug');

        CalendarEvent::query()->whereIn('google_event_id', $deletedEventIds)->delete();

        foreach ($affectedMeetingSlugs as $meetingSlug) {
            $this->publicMeetingReadModelCache->forgetBySlug($meetingSlug);
        }

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

        /** @phpstan-ignore-next-line */
        if ($extendedProperties) {
            $speaker = $extendedProperties['private']['speaker_name'] ?? null;
        }

        $attributes = [
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
            'is_categorized_automatically' => true,
        ];

        // The manual-categorisation check and the write must be atomic: an
        // administrator can categorise the event between an unlocked read and
        // the upsert, and the sync would then overwrite that manual choice
        // with its pattern-derived slug. Locking the row for the duration of
        // the decision makes a concurrent manual write wait until this
        // transaction commits (after which the manual write wins), or commit
        // first (in which case the locked read sees the manual flag).
        return DB::transaction(function () use ($googleEvent, $attributes): CalendarEvent {
            $existingEvent = CalendarEvent::query()
                /** @phpstan-ignore-next-line */
                ->where('google_event_id', $googleEvent->id)
                ->lockForUpdate()
                ->first();

            if ($existingEvent?->is_categorized_automatically === false) {
                unset($attributes['meeting_slug'], $attributes['is_categorized_automatically']);
            }

            return CalendarEvent::query()->updateOrCreate(
                /** @phpstan-ignore-next-line */
                ['google_event_id' => $googleEvent->id],
                $attributes
            );
        });
    }

    private function determineMeetingSlug(Event $googleEvent): ?string
    {
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
