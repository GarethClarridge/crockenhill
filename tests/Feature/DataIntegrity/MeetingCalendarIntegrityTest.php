<?php

namespace Tests\Feature\DataIntegrity;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingCalendarIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function updating_a_meeting_slug_updates_associated_calendar_events()
    {
        // 1. Create a meeting
        $meeting = Meeting::factory()->create([
            'slug' => 'old-slug',
        ]);

        // 2. Create associated calendar events
        $event1 = CalendarEvent::factory()->create([
            'meeting_slug' => 'old-slug',
        ]);
        $event2 = CalendarEvent::factory()->create([
            'meeting_slug' => 'old-slug',
        ]);

        // 3. Update the meeting slug
        $meeting->update(['slug' => 'new-slug']);

        // 4. Verify the calendar events now have the new slug
        $this->assertEquals('new-slug', $event1->fresh()->meeting_slug);
        $this->assertEquals('new-slug', $event2->fresh()->meeting_slug);
    }

    #[Test]
    public function deleting_a_meeting_deletes_associated_calendar_events()
    {
        // 1. Create a meeting
        $meeting = Meeting::factory()->create([
            'slug' => 'to-be-deleted',
        ]);

        // 2. Create associated calendar events
        $event = CalendarEvent::factory()->create([
            'meeting_slug' => 'to-be-deleted',
        ]);

        // 3. Delete the meeting
        $meeting->delete();

        // 4. Verify the calendar event is also deleted (due to cascade)
        $this->assertDatabaseMissing('calendar_events', ['id' => $event->id]);
    }
}
