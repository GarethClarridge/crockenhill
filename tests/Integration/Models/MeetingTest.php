<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\MeetingFrequency;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function meeting_accessors(): void
    {
        // Test getFormattedDateTimeAttribute
        $date = Carbon::create(2023, 1, 15, 10, 30, 0);
        $meetingWithDate = Meeting::factory()->onDate($date)->create();

        $this->assertEquals($date->format('F j, Y, g:i A'), $meetingWithDate->formatted_date_time);

        // Test location (formerly location_address)
        $address = '123 Main St, Anytown, AT 12345';
        $meetingWithAddress = Meeting::factory()->create(['location' => $address]);
        $this->assertEquals($address, $meetingWithAddress->location);

        $meetingWithoutAddress = Meeting::factory()->create(['location' => null]);
        $this->assertNull($meetingWithoutAddress->location);
    }

    #[Test]
    public function attribute_setters_perform_trimming_and_nulling(): void
    {
        $meeting = Meeting::factory()->create([
            'day' => '  Monday  ',
            'who' => '  Everyone  ',
            'location' => '  The Church Hall  ',
            'leaders_phone' => '  0123456789  ',
            'leaders_email' => '  TEST@EXAMPLE.COM  ',
        ]);

        $this->assertEquals('Monday', $meeting->day);
        $this->assertEquals('Everyone', $meeting->who);
        $this->assertEquals('The Church Hall', $meeting->location);
        $this->assertEquals('0123456789', $meeting->leaders_phone);
        $this->assertEquals('test@example.com', $meeting->leaders_email);

        $meeting->update([
            'day' => '   ',
            'location' => '',
            'leaders_phone' => null,
            'leaders_email' => "\t",
        ]);

        $this->assertNull($meeting->day);
        $this->assertNull($meeting->location);
        $this->assertNull($meeting->leaders_phone);
        $this->assertNull($meeting->leaders_email);
    }

    #[Test]
    public function meeting_mutators_and_casts()
    {
        // Test meeting_date casting to Carbon instance
        $meetingWithDate = Meeting::factory()->onDate(Carbon::now())->create();
        $this->assertInstanceOf(Carbon::class, $meetingWithDate->meeting_date);
        // Assumes: protected $casts = ['meeting_date' => 'datetime'];

        // Test is_recurring casting to boolean
        $recurringMeeting = Meeting::factory()->recurring()->create();
        $this->assertTrue($recurringMeeting->is_recurring);

        $nonRecurringMeeting = Meeting::factory()->notRecurring()->create();
        $this->assertFalse($nonRecurringMeeting->is_recurring);
        // Assumes: protected $casts = ['is_recurring' => 'boolean'];

        // Test frequency (assuming it's a string, no special cast yet)
        $meetingWithFrequency = Meeting::factory()->recurring('monthly')->create();
        $this->assertEquals(MeetingFrequency::Monthly, $meetingWithFrequency->frequency);

        $meetingWithoutFrequency = Meeting::factory()->notRecurring()->create();
        $this->assertNull($meetingWithoutFrequency->frequency);
    }

    #[Test]
    public function meeting_scopes()
    {
        // Test isRecurring() scope
        $recurringMeeting = Meeting::factory()->recurring()->create();
        $nonRecurringMeeting = Meeting::factory()->notRecurring()->create();

        $recurringMeetings = Meeting::isRecurring()->get();
        $this->assertTrue($recurringMeetings->contains($recurringMeeting));
        $this->assertFalse($recurringMeetings->contains($nonRecurringMeeting));
        // Assumes: public function scopeIsRecurring($query) { return $query->where('is_recurring', true); }

        // Test upcoming() scope
        $upcomingMeeting = Meeting::factory()->upcoming()->create();
        $pastMeeting = Meeting::factory()->past()->create();

        $upcomingMeetings = Meeting::upcoming()->get();
        $this->assertTrue($upcomingMeetings->contains($upcomingMeeting));
        $this->assertFalse($upcomingMeetings->contains($pastMeeting));
        // Assumes: public function scopeUpcoming($query) { return $query->where('meeting_date', '>=', Carbon::now()); }

        // Test onDate(Carbon $date) scope
        $targetDate = Carbon::create(2023, 5, 10, 14, 0, 0);
        $otherDate = Carbon::create(2023, 5, 11);

        $meetingOnTargetDate = Meeting::factory()->onDate($targetDate)->create();
        $meetingOnTargetDateDifferentTime = Meeting::factory()->onDate($targetDate->copy()->setTime(18, 0, 0))->create();
        $meetingOnOtherDate = Meeting::factory()->onDate($otherDate)->create();

        $meetingsOnDate = Meeting::onDate($targetDate)->get();
        $this->assertCount(2, $meetingsOnDate); // Should find both meetings on the target date, regardless of time
        $this->assertTrue($meetingsOnDate->contains($meetingOnTargetDate));
        $this->assertTrue($meetingsOnDate->contains($meetingOnTargetDateDifferentTime));
        $this->assertFalse($meetingsOnDate->contains($meetingOnOtherDate));
        // Assumes: public function scopeOnDate($query, Carbon $date) {
        //     return $query->whereDate('meeting_date', $date->toDateString());
        // }
    }

    #[Test]
    public function get_next_occurrence_handles_all_frequencies(): void
    {
        // Set fixed "now" for deterministic tests
        $now = Carbon::create(2024, 1, 15, 12, 0, 0);
        Carbon::setTestNow($now);

        // 1. Daily frequency
        $dailyMeeting = Meeting::factory()->recurring('daily')->create([
            'meeting_date' => $now->copy()->subDays(5),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->copy()->addHour()->format('H:i:s'),
        ]);
        $nextDaily = $dailyMeeting->getNextOccurrence();
        $this->assertNotNull($nextDaily, 'Daily meeting occurrence should not be null');
        $this->assertTrue($nextDaily->isSameDay($now), 'Daily meeting should occur today. Got: '.$nextDaily->toDateTimeString().' Expected: '.$now->toDateTimeString());
        $this->assertEquals($now->format('H:i:s'), $nextDaily->format('H:i:s'));

        // If time for today has passed
        Carbon::setTestNow($now->copy()->addHours(1));
        $nextDailyPast = $dailyMeeting->getNextOccurrence();
        $this->assertTrue($nextDailyPast->isSameDay($now->copy()->addDay()));

        // 2. Weekly frequency
        Carbon::setTestNow($now);
        $weeklyMeeting = Meeting::factory()->recurring('weekly')->create([
            'meeting_date' => $now->copy()->subWeeks(2),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->copy()->addHour()->format('H:i:s'),
        ]);
        $nextWeekly = $weeklyMeeting->getNextOccurrence();
        $this->assertTrue($nextWeekly->isSameDay($now));

        // 3. Monthly frequency (Normal)
        $monthlyMeeting = Meeting::factory()->recurring('monthly')->create([
            'meeting_date' => $now->copy()->subMonths(2),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->copy()->addHour()->format('H:i:s'),
        ]);
        $nextMonthly = $monthlyMeeting->getNextOccurrence();
        $this->assertTrue($nextMonthly->isSameDay($now));

        // 4. Monthly frequency (End of month clamping)
        // Set now to June 15th
        Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));
        // Meeting started on May 31st
        $eomDate = Carbon::create(2024, 5, 31, 19, 0, 0);
        $eomMeeting = Meeting::factory()->recurring('monthly')->create([
            'meeting_date' => $eomDate,
            'day' => $eomDate->format('l'),
            'start_time' => '19:00:00',
            'end_time' => '20:00:00',
        ]);
        $nextEom = $eomMeeting->getNextOccurrence();
        // June only has 30 days, so it should clamp to June 30th
        $this->assertTrue($nextEom->isSameDay(Carbon::create(2024, 6, 30)));
        $this->assertEquals('19:00:00', $nextEom->format('H:i:s'));

        // 5. Annual frequency (Normal)
        Carbon::setTestNow($now);
        $annualMeeting = Meeting::factory()->recurring('annually')->create([
            'meeting_date' => $now->copy()->subYears(1),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->copy()->addHour()->format('H:i:s'),
        ]);
        $nextAnnual = $annualMeeting->getNextOccurrence();
        $this->assertTrue($nextAnnual->isSameDay($now));

        // 6. Annual frequency (Leap year handling)
        Carbon::setTestNow(Carbon::create(2025, 1, 1));
        // Meeting started on Feb 29, 2024 (Leap year)
        $leapMeeting = Meeting::factory()->recurring('annually')->onDate(Carbon::create(2024, 2, 29))->create();
        $nextLeap = $leapMeeting->getNextOccurrence();
        // 2025 is not a leap year, should clamp to Feb 28th
        $this->assertTrue($nextLeap->isSameDay(Carbon::create(2025, 2, 28)));

        // 7. Non-recurring
        $nonRecurring = Meeting::factory()->notRecurring()->onDate($now->copy()->subDays(5))->create();
        $this->assertNull($nonRecurring->getNextOccurrence());

        // 8. Future start date
        Carbon::setTestNow($now); // Ensure we are back to Jan 15th
        $futureDate = Carbon::create(2024, 1, 18, 12, 0, 0);
        $futureMeeting = Meeting::factory()->recurring('weekly')->create();
        $futureMeeting->meeting_date = $futureDate;
        $futureMeeting->day = $futureDate->format('l');
        $futureMeeting->save();

        // Refresh to ensure we have the same precision/format as from DB
        $futureMeeting->refresh();

        $this->assertEquals($futureDate->toDateString(), $futureMeeting->meeting_date->toDateString(), 'Meeting date should be exactly 3 days from now');

        $nextFuture = $futureMeeting->getNextOccurrence();
        $this->assertNotNull($nextFuture, 'Next occurrence should not be null for future recurring meeting');
        $this->assertTrue($nextFuture->isSameDay($futureDate), 'Next occurrence should be the future start date. Got: '.($nextFuture ? $nextFuture->toDateTimeString() : 'null').' Expected: '.$futureDate->toDateTimeString());

        Carbon::setTestNow(); // Reset
    }
}
