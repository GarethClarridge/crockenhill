<?php

namespace Tests\Unit\Models;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $data = [
            'google_event_id' => 'abc123',
            'meeting_slug' => 'morning-service',
            'title' => 'Sunday Morning Service',
            'description' => 'Our weekly morning gathering',
            'speaker' => 'John Doe',
            'location' => 'Main Sanctuary',
            'start_datetime' => now()->addDay(),
            'end_datetime' => now()->addDay()->addHour(),
            'status' => 'confirmed',
            'is_categorized_automatically' => true,
        ];

        $event = new CalendarEvent($data);

        foreach ($data as $key => $value) {
            if ($value instanceof \Illuminate\Support\Carbon) {
                $this->assertEquals($value->timestamp, $event->$key->timestamp);
            } else {
                $this->assertEquals($value, $event->$key);
            }
        }
    }

    #[Test]
    public function it_casts_attributes_correctly(): void
    {
        $event = CalendarEvent::factory()->create([
            'start_datetime' => '2024-01-01 10:00:00',
            'end_datetime' => '2024-01-01 11:00:00',
            'is_categorized_automatically' => 1,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $event->start_datetime);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $event->end_datetime);
        $this->assertIsBool($event->is_categorized_automatically);
        $this->assertTrue($event->is_categorized_automatically);
    }

    #[Test]
    public function it_belongs_to_a_meeting(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'test-meeting']);
        $event = CalendarEvent::factory()->create(['meeting_slug' => 'test-meeting']);

        $this->assertInstanceOf(Meeting::class, $event->meeting);
        $this->assertEquals($meeting->id, $event->meeting->id);
    }

    #[Test]
    public function it_defines_upcoming_scope(): void
    {
        $past = CalendarEvent::factory()->create(['start_datetime' => now()->subDay()]);
        $upcoming = CalendarEvent::factory()->create(['start_datetime' => now()->addDay()]);

        $results = CalendarEvent::query()
            ->whereKey([$past->id, $upcoming->id])
            ->upcoming()
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals($upcoming->id, $results->first()->id);
    }

    #[Test]
    public function it_defines_past_scope(): void
    {
        $past = CalendarEvent::factory()->create(['start_datetime' => now()->subDay()]);
        $upcoming = CalendarEvent::factory()->create(['start_datetime' => now()->addDay()]);

        $results = CalendarEvent::query()
            ->whereKey([$past->id, $upcoming->id])
            ->past()
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals($past->id, $results->first()->id);
    }

    #[Test]
    public function it_defines_confirmed_scope(): void
    {
        $confirmed = CalendarEvent::factory()->create(['status' => 'confirmed']);
        $tentative = CalendarEvent::factory()->create(['status' => 'tentative']);

        $results = CalendarEvent::query()
            ->whereKey([$confirmed->id, $tentative->id])
            ->confirmed()
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals($confirmed->id, $results->first()->id);
    }
}
