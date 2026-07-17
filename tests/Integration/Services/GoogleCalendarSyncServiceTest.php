<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Services\Calendar\GoogleCalendarSyncService;
use App\Services\Public\PublicMeetingReadModelCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\GoogleCalendar\Event;
use Tests\TestCase;

class GoogleCalendarSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private GoogleCalendarSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GoogleCalendarSyncService::class);
    }

    #[Test]
    public function it_syncs_one_off_events_as_uncategorized_when_no_meeting_matches(): void
    {
        $event = $this->makeGoogleEvent(
            id: 'one-off-event',
            name: 'Church Picnic',
        );

        $calendarEvent = $this->service->syncSingleEvent($event);

        $this->assertNull($calendarEvent->meeting_slug);
        $this->assertTrue($calendarEvent->is_categorized_automatically);
    }

    #[Test]
    public function it_preserves_manual_categorization_while_updating_google_fields(): void
    {
        Meeting::factory()->create(['slug' => 'pattern-meeting']);
        Meeting::factory()->create(['slug' => 'manual-meeting']);
        config(['calendar.meeting_patterns' => [
            'pattern-meeting' => [
                'patterns' => ['Bible study'],
                'case_insensitive' => true,
            ],
        ]]);

        CalendarEvent::factory()->create([
            'google_event_id' => 'manual-event',
            'meeting_slug' => 'manual-meeting',
            'title' => 'Old title',
            'is_categorized_automatically' => false,
        ]);

        $event = $this->makeGoogleEvent(
            id: 'manual-event',
            name: 'Bible Study Updated',
        );

        $calendarEvent = $this->service->syncSingleEvent($event);

        $this->assertSame('manual-meeting', $calendarEvent->meeting_slug);
        $this->assertFalse($calendarEvent->is_categorized_automatically);
        $this->assertSame('Bible Study Updated', $calendarEvent->title);
    }

    #[Test]
    public function it_recategorizes_automatically_categorized_events_from_patterns(): void
    {
        Meeting::factory()->create(['slug' => 'pattern-meeting']);
        config(['calendar.meeting_patterns' => [
            'pattern-meeting' => [
                'patterns' => ['Bible study'],
                'case_insensitive' => true,
            ],
        ]]);

        CalendarEvent::factory()->create([
            'google_event_id' => 'automatic-event',
            'meeting_slug' => null,
            'is_categorized_automatically' => true,
        ]);

        $calendarEvent = $this->service->syncSingleEvent($this->makeGoogleEvent(
            id: 'automatic-event',
            name: 'Bible Study Updated',
        ));

        $this->assertSame('pattern-meeting', $calendarEvent->meeting_slug);
        $this->assertTrue($calendarEvent->is_categorized_automatically);
    }

    #[Test]
    public function it_does_not_delete_local_event_when_single_event_fails_to_process(): void
    {
        // A local event that Google returned but whose processing threw an exception.
        // Before the fix, this event would be deleted because it was never added to
        // $processedEventIds. After the fix, $seenUpstreamIds protects it.
        CalendarEvent::factory()->create([
            'google_event_id' => 'event-that-fails',
            'start_datetime' => now()->addDays(7),
            'end_datetime' => now()->addDays(7)->addHour(),
        ]);

        $fakeGoogleEvent = $this->makeGoogleEvent(id: 'event-that-fails', name: 'Failing Event');

        $service = $this->partiallyMockedService();

        $service->shouldReceive('fetchEventsFromGoogle')
            ->once()
            ->andReturn(collect([$fakeGoogleEvent]));

        $service->shouldReceive('syncSingleEvent')
            ->with($fakeGoogleEvent)
            ->andThrow(new \Exception('Simulated processing failure'));

        $service->syncFromGoogleCalendar();

        $this->assertDatabaseHas('calendar_events', ['google_event_id' => 'event-that-fails']);
    }

    #[Test]
    public function it_deletes_events_genuinely_removed_from_google(): void
    {
        // A local event that Google no longer returns — it was truly deleted upstream.
        CalendarEvent::factory()->create([
            'google_event_id' => 'removed-from-google',
            'start_datetime' => now()->addDays(7),
            'end_datetime' => now()->addDays(7)->addHour(),
        ]);

        $service = $this->partiallyMockedService();

        $service->shouldReceive('fetchEventsFromGoogle')
            ->once()
            ->andReturn(collect([]));

        $service->syncFromGoogleCalendar();

        $this->assertDatabaseMissing('calendar_events', ['google_event_id' => 'removed-from-google']);
    }

    #[Test]
    public function it_forgets_the_public_meeting_read_model_when_google_removals_are_bulk_deleted(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'bulk-delete-meeting']);
        $event = CalendarEvent::factory()->create([
            'google_event_id' => 'removed-from-google',
            'meeting_slug' => 'bulk-delete-meeting',
            'status' => 'confirmed',
            'start_datetime' => now()->addDays(7),
            'end_datetime' => now()->addDays(7)->addHour(),
        ]);

        $cache = app(PublicMeetingReadModelCache::class);
        $warmReadModel = $cache->get($meeting);
        $this->assertTrue(
            $warmReadModel->upcomingEvents->contains('id', $event->id),
            'expected the warmed read model to contain the upcoming event',
        );

        $service = $this->partiallyMockedService();
        $service->shouldReceive('fetchEventsFromGoogle')
            ->once()
            ->andReturn(collect([]));

        $service->syncFromGoogleCalendar();

        $refreshedReadModel = $cache->get($meeting->refresh());
        $this->assertFalse(
            $refreshedReadModel->upcomingEvents->contains('id', $event->id),
            'expected the bulk-deleted event to be evicted from the public meeting read model',
        );
    }

    #[Test]
    public function it_locks_the_existing_row_inside_a_transaction_while_deciding_categorisation(): void
    {
        CalendarEvent::factory()->create([
            'google_event_id' => 'locked-event',
            'is_categorized_automatically' => true,
        ]);

        $baseTransactionLevel = DB::transactionLevel();

        /** @var list<array{sql: string, level: int}> $queries */
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        });

        $this->service->syncSingleEvent($this->makeGoogleEvent(
            id: 'locked-event',
            name: 'Church Picnic',
        ));

        $lockedRead = collect($queries)->first(
            fn (array $query): bool => str_contains($query['sql'], 'calendar_events')
                && str_contains(strtolower($query['sql']), 'for update'),
        );

        $this->assertNotNull(
            $lockedRead,
            'expected the sync to read the existing row with a pessimistic lock (select ... for update)',
        );
        $this->assertGreaterThan(
            $baseTransactionLevel,
            $lockedRead['level'],
            'expected the locked read to run inside the sync transaction',
        );
    }

    /**
     * @return GoogleCalendarSyncService&Mockery\MockInterface
     */
    private function partiallyMockedService(): GoogleCalendarSyncService
    {
        /** @var GoogleCalendarSyncService&Mockery\MockInterface $service */
        $service = Mockery::mock(GoogleCalendarSyncService::class, [
            app(PublicMeetingReadModelCache::class),
        ])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        return $service;
    }

    private function makeGoogleEvent(string $id, string $name): Event
    {
        $event = new Event;
        $event->id = $id;
        $event->name = $name;
        $event->description = 'Calendar event description';
        $event->location = 'Church hall';
        $event->startDateTime = Carbon::create(2026, 3, 15, 10, 0, 0);
        $event->endDateTime = Carbon::create(2026, 3, 15, 11, 0, 0);
        $event->status = 'confirmed';

        return $event;
    }
}
