<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\MeetingFrequency;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingRecursionTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_calculates_next_daily_occurrence_correctly(): void
    {
        // Set fixed "now" for testing
        $now = Carbon::create(2025, 5, 10, 12, 0, 0);
        Carbon::setTestNow($now);

        // Case 1: Meeting started in the past, same time as now (should be today)
        $meeting = Meeting::factory()->make([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Daily,
            'meeting_date' => $now->copy()->subDays(5), // Time is 12:00:00
        ]);
        $this->assertEquals($now, $meeting->getNextOccurrence());

        // Case 2: Meeting started in the past, time later today (14:00)
        $meeting->meeting_date = $now->copy()->subDays(5)->setTime(14, 0, 0);
        $this->assertEquals($now->copy()->setTime(14, 0, 0), $meeting->getNextOccurrence());

        // Case 3: Meeting started in the past, time already passed today (10:00)
        $meeting->meeting_date = $now->copy()->subDays(5)->setTime(10, 0, 0);
        $this->assertEquals($now->copy()->addDay()->setTime(10, 0, 0), $meeting->getNextOccurrence());

        // Case 4: Meeting date in the future
        $futureDate = $now->copy()->addDays(5);
        $meeting->meeting_date = $futureDate;
        $this->assertEquals($futureDate, $meeting->getNextOccurrence());

        Carbon::setTestNow(); // Reset
    }

    #[Test]
    public function it_calculates_next_weekly_occurrence_correctly(): void
    {
        // Set fixed "now" for testing (a Saturday)
        $now = Carbon::create(2025, 5, 10, 12, 0, 0);
        Carbon::setTestNow($now);

        // Case 1: Meeting started in the past on same day, same time
        $meeting = Meeting::factory()->make([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly,
            'meeting_date' => $now->copy()->subWeeks(2), // Time is 12:00:00
        ]);
        $this->assertEquals($now, $meeting->getNextOccurrence());

        // Case 2: Meeting started in the past on same day, time later today (14:00)
        $meeting->meeting_date = $now->copy()->subWeeks(2)->setTime(14, 0, 0);
        $this->assertEquals($now->copy()->setTime(14, 0, 0), $meeting->getNextOccurrence());

        // Case 3: Meeting started in the past on same day, time already passed today (10:00)
        $meeting->meeting_date = $now->copy()->subWeeks(2)->setTime(10, 0, 0);
        $this->assertEquals($now->copy()->addWeek()->setTime(10, 0, 0), $meeting->getNextOccurrence());

        // Case 4: Meeting started in the past on a different day (e.g., Friday)
        $meeting->meeting_date = $now->copy()->subWeeks(2)->subDay()->setTime(10, 0, 0); // Friday 2 weeks ago
        // Next occurrence should be next Friday
        $this->assertEquals($now->copy()->addDays(6)->setTime(10, 0, 0), $meeting->getNextOccurrence());

        Carbon::setTestNow(); // Reset
    }

    #[Test]
    public function it_calculates_next_monthly_occurrence_correctly(): void
    {
        // Set fixed "now" for testing: May 10, 2025
        $now = Carbon::create(2025, 5, 10, 12, 0, 0);
        Carbon::setTestNow($now);

        // Case 1: Normal monthly recurrence (15th)
        $meeting = Meeting::factory()->make([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Monthly,
            'meeting_date' => Carbon::create(2025, 1, 15, 10, 0, 0),
        ]);
        // Should be May 15th
        $this->assertEquals(Carbon::create(2025, 5, 15, 10, 0, 0), $meeting->getNextOccurrence());

        // Case 2: 31st of the month (current month May has 31 days)
        $meeting->meeting_date = Carbon::create(2025, 1, 31, 10, 0, 0);
        $this->assertEquals(Carbon::create(2025, 5, 31, 10, 0, 0), $meeting->getNextOccurrence());

        // Case 3: 31st of the month, but next month (June) has 30 days
        Carbon::setTestNow($now->copy()->day(31)); // May 31st
        // Next occurrence should be July 31st because June has only 30 days
        // Jan 31 -> Feb (no 31) -> March 31 -> April (no 31) -> May 31 -> June (no 31) -> July 31
        $this->assertEquals(Carbon::create(2025, 7, 31, 10, 0, 0), $meeting->getNextOccurrence());

        Carbon::setTestNow(); // Reset
    }

    #[Test]
    public function it_calculates_next_annual_occurrence_correctly(): void
    {
        // Set fixed "now" for testing: May 10, 2025
        $now = Carbon::create(2025, 5, 10, 12, 0, 0);
        Carbon::setTestNow($now);

        // Case 1: Normal annual recurrence
        $meeting = Meeting::factory()->make([
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Annually,
            'meeting_date' => Carbon::create(2024, 6, 15, 10, 0, 0),
        ]);
        // Should be June 15th 2025
        $this->assertEquals(Carbon::create(2025, 6, 15, 10, 0, 0), $meeting->getNextOccurrence());

        // Case 2: February 29th in a non-leap year
        $meeting->meeting_date = Carbon::create(2024, 2, 29, 10, 0, 0);

        // Since May 10, 2025 is PAST March 1, 2025, it should be March 1, 2026
        $this->assertEquals(Carbon::create(2026, 3, 1, 10, 0, 0), $meeting->getNextOccurrence());

        // Verify it works for the current year if we are before the date
        Carbon::setTestNow(Carbon::create(2025, 1, 1, 12, 0, 0));
        $this->assertEquals(Carbon::create(2025, 3, 1, 10, 0, 0), $meeting->getNextOccurrence());

        Carbon::setTestNow(); // Reset
    }
}
