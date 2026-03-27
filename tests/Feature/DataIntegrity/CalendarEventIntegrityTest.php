<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Enums\CalendarEventStatus;
use App\Models\CalendarEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CalendarEventIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_allows_valid_status_values(): void
    {
        $event = CalendarEvent::factory()->create(['status' => CalendarEventStatus::CONFIRMED]);
        $this->assertEquals(CalendarEventStatus::CONFIRMED, $event->fresh()->status);

        $event->update(['status' => CalendarEventStatus::PENDING]);
        $this->assertEquals(CalendarEventStatus::PENDING, $event->fresh()->status);
    }

    /** @test */
    public function it_rejects_invalid_status_values_at_database_level(): void
    {
        if (\DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not enforce ENUM constraints.');
        }

        $this->expectException(QueryException::class);

        // Bypass Eloquent casts to test raw DB integrity
        \DB::table('calendar_events')->insert([
            'google_event_id' => 'test-google-id',
            'title' => 'Test Event',
            'start_datetime' => now(),
            'end_datetime' => now()->addHour(),
            'status' => 'invalid-status',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function it_allows_end_datetime_equal_to_start_datetime(): void
    {
        $now = now()->startOfMinute();
        $event = CalendarEvent::factory()->create([
            'start_datetime' => $now,
            'end_datetime' => $now,
        ]);

        $this->assertTrue($event->fresh()->end_datetime->equalTo($event->fresh()->start_datetime));
    }

    /** @test */
    public function it_allows_end_datetime_after_start_datetime(): void
    {
        $now = now()->startOfMinute();
        $event = CalendarEvent::factory()->create([
            'start_datetime' => $now,
            'end_datetime' => (clone $now)->addHour(),
        ]);

        $this->assertTrue($event->fresh()->end_datetime->isAfter($event->fresh()->start_datetime));
    }

    /** @test */
    public function it_rejects_end_datetime_before_start_datetime_at_database_level(): void
    {
        if (\DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Check constraints are not enforced in the current SQLite configuration/migration.');
        }

        $now = now()->startOfMinute();

        try {
            \DB::table('calendar_events')->insert([
                'google_event_id' => 'test-google-id-2',
                'title' => 'Invalid Timing Event',
                'start_datetime' => $now,
                'end_datetime' => (clone $now)->subHour(), // Invalid: end before start
                'status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('calendar_events_timing_check', $e->getMessage());

            return;
        }

        $this->fail('Failed asserting that exception of type "Illuminate\Database\QueryException" is thrown for timing check constraint.');
    }
}
