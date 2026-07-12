<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\MeetingFrequency;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
}
