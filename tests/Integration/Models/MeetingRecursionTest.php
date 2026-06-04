<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\MeetingFrequency;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingRecursionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function daily_recurrence_is_today_if_time_is_future(): void
    {
        Carbon::setTestNow('2024-01-01 10:00:00');

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Daily,
            'meeting_date' => '2023-12-01 12:00:00', // Past start date
        ]);

        $next = $meeting->getNextOccurrence();

        $this->assertEquals('2024-01-01 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function daily_recurrence_is_tomorrow_if_time_is_past(): void
    {
        Carbon::setTestNow('2024-01-01 14:00:00');

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Daily,
            'meeting_date' => '2023-12-01 12:00:00',
        ]);

        $next = $meeting->getNextOccurrence();

        $this->assertEquals('2024-01-02 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function weekly_recurrence_is_today_if_same_day_and_future_time(): void
    {
        Carbon::setTestNow('2024-01-01 10:00:00'); // Monday

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly,
            'meeting_date' => '2023-12-25 12:00:00', // Previous Monday
        ]);

        $next = $meeting->getNextOccurrence();

        $this->assertEquals('2024-01-01 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function weekly_recurrence_is_next_week_if_same_day_and_past_time(): void
    {
        Carbon::setTestNow('2024-01-01 14:00:00'); // Monday

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly,
            'meeting_date' => '2023-12-25 12:00:00', // Previous Monday
        ]);

        $next = $meeting->getNextOccurrence();

        $this->assertEquals('2024-01-08 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function monthly_recurrence_on_31st_lands_on_last_day_of_february_if_now_is_february(): void
    {
        Carbon::setTestNow('2024-02-01 10:00:00');

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Monthly,
            'meeting_date' => '2024-01-31 12:00:00',
        ]);

        $next = $meeting->getNextOccurrence();

        // 2024 is leap year, so Feb has 29 days. 31st is clamped to 29th.
        $this->assertEquals('2024-02-29 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function monthly_recurrence_past_current_month_lands_on_next_month(): void
    {
        Carbon::setTestNow('2024-03-02 14:00:00');

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Monthly,
            'meeting_date' => '2024-01-01 12:00:00',
        ]);

        $next = $meeting->getNextOccurrence();
        $this->assertEquals('2024-04-01 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function monthly_recurrence_preserves_day(): void
    {
        Carbon::setTestNow('2024-01-16 10:00:00');

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Monthly,
            'meeting_date' => '2023-12-15 12:00:00',
        ]);

        $next = $meeting->getNextOccurrence();

        $this->assertEquals('2024-02-15 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function annual_recurrence_handles_leap_year_rollover(): void
    {
        Carbon::setTestNow('2025-01-01 10:00:00');

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Annually,
            'meeting_date' => '2024-02-29 12:00:00', // Leap day
        ]);

        $next = $meeting->getNextOccurrence();

        // In 2025, Feb 29 is clamped to Feb 28th
        $this->assertEquals('2025-02-28 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function annual_recurrence_is_next_year_if_past_this_year(): void
    {
        Carbon::setTestNow('2024-06-01 10:00:00');

        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Annually,
            'meeting_date' => '2024-01-01 12:00:00',
        ]);

        $next = $meeting->getNextOccurrence();

        $this->assertEquals('2025-01-01 12:00:00', $next->toDateTimeString());
    }

    #[Test]
    public function next_occurrence_returns_meeting_date_if_in_future(): void
    {
        Carbon::setTestNow('2024-01-01 10:00:00');

        $futureDate = '2024-02-01 12:00:00';
        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Daily,
            'meeting_date' => $futureDate,
        ]);

        $next = $meeting->getNextOccurrence();

        $this->assertEquals($futureDate, $next->toDateTimeString());
    }

    #[Test]
    public function next_occurrence_returns_null_if_not_recurring(): void
    {
        $meeting = Meeting::factory()->create([
            'is_recurring' => false,
            'meeting_date' => '2023-12-01 12:00:00',
        ]);

        $this->assertNull($meeting->getNextOccurrence());
    }
}
