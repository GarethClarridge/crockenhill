<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\MeetingFrequency;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function heading_accessor_uses_page_heading_if_available(): void
    {
        $page = Page::factory()->create(['heading' => 'Custom Page Heading']);
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
            'page_id' => $page->id,
        ]);

        $this->assertEquals('Custom Page Heading', $meeting->heading);
    }

    #[Test]
    public function heading_accessor_falls_back_to_slug_if_no_page(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting-slug',
            'page_id' => null,
        ]);

        $this->assertEquals('Test Meeting Slug', $meeting->heading);
    }

    #[Test]
    public function formatted_date_time_accessor(): void
    {
        // Test with date and time
        $date = Carbon::create(2023, 1, 15, 10, 30, 0);
        $meetingWithDate = Meeting::factory()->create([
            'meeting_date' => $date,
            'start_time' => '10:30:00',
        ]);
        $this->assertEquals('January 15, 2023, 10:30 AM', $meetingWithDate->formatted_date_time);

        // Test with date only (falls back to 12:00 AM)
        $meetingDateOnly = Meeting::factory()->create([
            'meeting_date' => '2023-01-15',
            'start_time' => null,
        ]);
        $this->assertEquals('January 15, 2023, 12:00 AM', $meetingDateOnly->formatted_date_time);

        // Test with null date
        $meetingNoDate = Meeting::factory()->create(['meeting_date' => null]);
        $this->assertNull($meetingNoDate->formatted_date_time);
    }

    #[Test]
    public function has_content_returns_true_if_page_is_linked(): void
    {
        $page = Page::factory()->create();
        $meetingWithPage = Meeting::factory()->create(['page_id' => $page->id]);
        $meetingWithoutPage = Meeting::factory()->create(['page_id' => null]);

        $this->assertTrue($meetingWithPage->hasContent());
        $this->assertFalse($meetingWithoutPage->hasContent());
    }

    #[Test]
    public function has_photos_returns_correct_boolean(): void
    {
        Storage::fake('public');

        $meeting = Meeting::factory()->create();
        $this->assertFalse($meeting->hasPhotos());

        // Add a mock photo manually to the media table
        $meeting->addMedia(public_path('images/Primary.png'))
            ->preservingOriginal()
            ->toMediaCollection('photos');

        $this->assertTrue($meeting->fresh()->hasPhotos());
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
        $meetingWithDate = Meeting::factory()->onDate(Carbon::now())->create();
        $this->assertInstanceOf(Carbon::class, $meetingWithDate->meeting_date);

        $recurringMeeting = Meeting::factory()->recurring()->create();
        $this->assertTrue($recurringMeeting->is_recurring);

        $nonRecurringMeeting = Meeting::factory()->notRecurring()->create();
        $this->assertFalse($nonRecurringMeeting->is_recurring);

        $meetingWithFrequency = Meeting::factory()->recurring('monthly')->create();
        $this->assertEquals(MeetingFrequency::Monthly, $meetingWithFrequency->frequency);
    }

    #[Test]
    public function meeting_scopes()
    {
        $recurringMeeting = Meeting::factory()->recurring()->create();
        $nonRecurringMeeting = Meeting::factory()->notRecurring()->create();

        $recurringMeetings = Meeting::isRecurring()->get();
        $this->assertTrue($recurringMeetings->contains($recurringMeeting));
        $this->assertFalse($recurringMeetings->contains($nonRecurringMeeting));

        $upcomingMeeting = Meeting::factory()->upcoming()->create();
        $pastMeeting = Meeting::factory()->past()->create();

        $upcomingMeetings = Meeting::upcoming()->get();
        $this->assertTrue($upcomingMeetings->contains($upcomingMeeting));
        $this->assertFalse($upcomingMeetings->contains($pastMeeting));

        $targetDate = Carbon::create(2023, 5, 10, 14, 0, 0);
        $otherDate = Carbon::create(2023, 5, 11);

        $meetingOnTargetDate = Meeting::factory()->onDate($targetDate)->create();
        $meetingOnOtherDate = Meeting::factory()->onDate($otherDate)->create();

        $meetingsOnDate = Meeting::onDate($targetDate)->get();
        $this->assertTrue($meetingsOnDate->contains($meetingOnTargetDate));
        $this->assertFalse($meetingsOnDate->contains($meetingOnOtherDate));
    }

    #[Test]
    public function get_next_occurrence_handles_all_frequencies(): void
    {
        $now = Carbon::create(2024, 1, 15, 12, 0, 0);
        Carbon::setTestNow($now);

        $dailyMeeting = Meeting::factory()->recurring('daily')->create([
            'meeting_date' => $now->copy()->subDays(5),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->copy()->addHour()->format('H:i:s'),
        ]);
        $nextDaily = $dailyMeeting->getNextOccurrence();
        $this->assertTrue($nextDaily->isSameDay($now));

        Carbon::setTestNow($now->copy()->addHours(1));
        $nextDailyPast = $dailyMeeting->getNextOccurrence();
        $this->assertTrue($nextDailyPast->isSameDay($now->copy()->addDay()));

        Carbon::setTestNow($now);
        $weeklyMeeting = Meeting::factory()->recurring('weekly')->create([
            'meeting_date' => $now->copy()->subWeeks(2),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->copy()->addHour()->format('H:i:s'),
        ]);
        $nextWeekly = $weeklyMeeting->getNextOccurrence();
        $this->assertTrue($nextWeekly->isSameDay($now));

        $monthlyMeeting = Meeting::factory()->recurring('monthly')->create([
            'meeting_date' => $now->copy()->subMonths(2),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->copy()->addHour()->format('H:i:s'),
        ]);
        $nextMonthly = $monthlyMeeting->getNextOccurrence();
        $this->assertTrue($nextMonthly->isSameDay($now));

        Carbon::setTestNow($now);
        $annualMeeting = Meeting::factory()->recurring('annually')->create([
            'meeting_date' => $now->copy()->subYears(1),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->copy()->addHour()->format('H:i:s'),
        ]);
        $nextAnnual = $annualMeeting->getNextOccurrence();
        $this->assertTrue($nextAnnual->isSameDay($now));

        $nonRecurring = Meeting::factory()->notRecurring()->onDate($now->copy()->subDays(5))->create();
        $this->assertNull($nonRecurring->getNextOccurrence());

        Carbon::setTestNow();
    }
}
