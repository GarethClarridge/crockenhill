<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Crockenhill\Meeting; // Assuming Crockenhill namespace
use Database\Factories\MeetingFactory; // Will create if not exists
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function testMeetingRelationships()
    {
        // No relationships are defined on the Meeting model yet.
        // This test serves as a placeholder.
        // Example: if Meeting hasMany Attendees:
        // $meeting = Meeting::factory()->has(Attendee::factory()->count(3), 'attendees')->create();
        // $this->assertCount(3, $meeting->attendees);
        $meeting = Meeting::factory()->create();
        $this->assertInstanceOf(Meeting::class, $meeting);
        $this->assertTrue(true); // Basic assertion
    }

    #[Test]
    public function testMeetingAccessors()
    {
        // Test getFormattedDateTimeAttribute
        $date = Carbon::create(2023, 1, 15, 10, 30, 0);
        $meetingWithDate = Meeting::factory()->onDate($date)->create();
        // Assuming a format like 'F j, Y, g:i A' (e.g., January 15, 2023, 10:30 AM)
        $this->assertEquals($date->format('F j, Y, g:i A'), $meetingWithDate->formatted_date_time);
        // Assumes: public function getFormattedDateTimeAttribute() { return $this->meeting_date ? $this->meeting_date->format('F j, Y, g:i A') : null; }

        // Test getLocationAddressAttribute (assuming it directly returns location_address)
        $address = '123 Main St, Anytown, AT 12345';
        $meetingWithAddress = Meeting::factory()->create(['location_address' => $address]);
        $this->assertEquals($address, $meetingWithAddress->location_address);
        // Assumes: public function getLocationAddressAttribute() { return $this->attributes['location_address']; }
        // Or, it's just a direct attribute access if no transformation is needed.

        $meetingWithoutAddress = Meeting::factory()->create(['location_address' => null]);
        $this->assertNull($meetingWithoutAddress->location_address);
    }

    #[Test]
    public function testMeetingMutatorsAndCasts()
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
        $this->assertEquals('monthly', $meetingWithFrequency->frequency);

        $meetingWithoutFrequency = Meeting::factory()->notRecurring()->create();
        $this->assertNull($meetingWithoutFrequency->frequency);
    }

    #[Test]
    public function testMeetingScopes()
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
        $meetingOnTargetDateDifferentTime = Meeting::factory()->onDate($targetDate->copy()->setTime(18,0,0))->create();
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
    public function testCustomMeetingMethods()
    {
        // Test getNextOccurrence() method for a weekly recurring meeting
        $today = Carbon::now();
        // Create a meeting that happened last week on the same weekday as today
        $lastWeekMeetingDate = $today->copy()->subWeek();
        $weeklyMeeting = Meeting::factory()
            ->recurring('weekly')
            ->onDate($lastWeekMeetingDate)
            ->create();

        $nextOccurrence = $weeklyMeeting->getNextOccurrence();
        $this->assertInstanceOf(Carbon::class, $nextOccurrence);
        // The next occurrence should be today (if time hasn't passed) or next week on the same day.
        // For simplicity, checking if it's the same day of week and in the future or today.
        $this->assertTrue($nextOccurrence->isSameDay($today) || $nextOccurrence->isSameDay($today->copy()->addWeek()));
        $this->assertTrue($nextOccurrence->greaterThanOrEqualTo($today->startOfDay()));
        $this->assertEquals($lastWeekMeetingDate->format('H:i:s'), $nextOccurrence->format('H:i:s')); // Should keep the same time

        // Test with a non-recurring meeting
        $nonRecurringMeeting = Meeting::factory()->notRecurring()->onDate(Carbon::now()->subDays(5))->create();
        $this->assertNull($nonRecurringMeeting->getNextOccurrence());

        // Test with a recurring meeting whose start date is in the future
        $futureStartDate = Carbon::now()->addMonth();
        $futureRecurringMeeting = Meeting::factory()
            ->recurring('monthly')
            ->onDate($futureStartDate)
            ->create();
        $nextFutureOccurrence = $futureRecurringMeeting->getNextOccurrence();
        $this->assertInstanceOf(Carbon::class, $nextFutureOccurrence);
        $this->assertTrue($nextFutureOccurrence->isSameDay($futureStartDate));


        // Assumes a method like (simplified for weekly):
        // public function getNextOccurrence(): ?Carbon {
        //     if (!$this->is_recurring || !$this->meeting_date || $this->frequency !== 'weekly') {
        //         return null;
        //     }
        //     $next = $this->meeting_date->copy();
        //     $now = Carbon::now();
        //     while($next->lessThan($now)) {
        //         $next->addWeek();
        //     }
        //     return $next;
        // }
        // More complex logic needed for various frequencies like 'monthly', 'annually' etc.
    }
}
