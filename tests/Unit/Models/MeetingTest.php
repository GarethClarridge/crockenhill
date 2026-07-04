<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use DatabaseTransactions;

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
        $meeting = Meeting::factory()->make([
            'slug' => 'test-meeting-slug',
            'page_id' => null,
        ]);

        $this->assertEquals('Test Meeting Slug', $meeting->heading);
    }

    #[Test]
    public function formatted_date_time_returns_null_if_no_meeting_date(): void
    {
        $meeting = Meeting::factory()->make(['meeting_date' => null]);

        $this->assertNull($meeting->formatted_date_time);
    }

    #[Test]
    public function formatted_date_time_formats_correctly_without_start_time(): void
    {
        $meeting = Meeting::factory()->make([
            'meeting_date' => '2023-01-15',
            'start_time' => null,
        ]);

        $this->assertEquals('January 15, 2023, 12:00 AM', $meeting->formatted_date_time);
    }

    #[Test]
    public function formatted_date_time_formats_correctly_with_start_time(): void
    {
        $meeting = Meeting::factory()->make([
            'meeting_date' => '2023-01-15',
            'start_time' => '10:30:00',
        ]);

        $this->assertEquals('January 15, 2023, 10:30 AM', $meeting->formatted_date_time);
    }

    #[Test]
    public function setters_trim_input_values(): void
    {
        $meeting = new Meeting();
        $meeting->day = ' Monday  ';
        $meeting->who = ' Everyone ';
        $meeting->location = ' Hall ';
        $meeting->leaders_phone = ' 12345 ';

        $this->assertEquals('Monday', $meeting->day);
        $this->assertEquals('Everyone', $meeting->who);
        $this->assertEquals('Hall', $meeting->location);
        $this->assertEquals('12345', $meeting->leaders_phone);
    }

    #[Test]
    public function leaders_email_setter_trims_and_lowercases(): void
    {
        $meeting = new Meeting();
        $meeting->leaders_email = ' TEST@Example.Com ';

        $this->assertEquals('test@example.com', $meeting->leaders_email);
    }

    #[Test]
    public function has_content_returns_true_if_page_is_linked(): void
    {
        $page = Page::factory()->create();
        $meetingWithPage = Meeting::factory()->create(['page_id' => $page->id]);
        $meetingWithoutPage = Meeting::factory()->make(['page_id' => null]);

        $this->assertTrue($meetingWithPage->hasContent());
        $this->assertFalse($meetingWithoutPage->hasContent());
    }

    #[Test]
    public function has_photos_returns_correct_boolean(): void
    {
        $meeting = Meeting::factory()->create();

        $this->assertFalse($meeting->hasPhotos());

        // Add a mock photo manually to the media table to avoid network/disk issues in unit test
        $meeting->addMedia(public_path('images/Primary.png'))
            ->preservingOriginal()
            ->toMediaCollection('photos');

        $this->assertTrue($meeting->fresh()->hasPhotos());
    }
}
