<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CalendarEventStatus;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarEventIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_trims_whitespace_from_attributes(): void
    {
        $event = new CalendarEvent([
            'google_event_id' => '  google-123  ',
            'title' => '  Morning Service  ',
            'speaker' => '  John Doe  ',
            'location' => '  Main Hall  ',
            'start_datetime' => now(),
            'end_datetime' => now()->addHour(),
            'status' => CalendarEventStatus::Confirmed,
        ]);

        $this->assertEquals('google-123', $event->google_event_id);
        $this->assertEquals('Morning Service', $event->title);
        $this->assertEquals('John Doe', $event->speaker);
        $this->assertEquals('Main Hall', $event->location);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $rules = CalendarEvent::validationRules();
        $validator = Validator::make([], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('google_event_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
        $this->assertArrayHasKey('start_datetime', $validator->errors()->toArray());
        $this->assertArrayHasKey('end_datetime', $validator->errors()->toArray());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_max_lengths(): void
    {
        $rules = CalendarEvent::validationRules();
        $longString = str_repeat('a', 256);

        $validator = Validator::make([
            'google_event_id' => $longString,
            'title' => $longString,
            'speaker' => $longString,
            'location' => $longString,
            'meeting_slug' => $longString,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('google_event_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
        $this->assertArrayHasKey('speaker', $validator->errors()->toArray());
        $this->assertArrayHasKey('location', $validator->errors()->toArray());
        $this->assertArrayHasKey('meeting_slug', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_meeting_slug_existence(): void
    {
        $rules = CalendarEvent::validationRules();

        $validator = Validator::make([
            'meeting_slug' => 'non-existent-slug',
        ], $rules);

        $this->assertArrayHasKey('meeting_slug', $validator->errors()->toArray());

        $meeting = Meeting::factory()->create(['slug' => 'test-meeting']);

        $validator = Validator::make([
            'meeting_slug' => 'test-meeting',
        ], $rules);

        $this->assertArrayNotHasKey('meeting_slug', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_status_enum(): void
    {
        $rules = CalendarEvent::validationRules();

        $validator = Validator::make([
            'status' => 'invalid-status',
        ], $rules);

        $this->assertArrayHasKey('status', $validator->errors()->toArray());

        $validator = Validator::make([
            'status' => CalendarEventStatus::Confirmed->value,
        ], $rules);

        $this->assertArrayNotHasKey('status', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_date_sequence(): void
    {
        $rules = CalendarEvent::validationRules();

        $validator = Validator::make([
            'start_datetime' => '2023-01-01 10:00:00',
            'end_datetime' => '2023-01-01 09:00:00',
        ], $rules);

        $this->assertArrayHasKey('end_datetime', $validator->errors()->toArray());
    }

    #[Test]
    public function it_enforces_database_integrity_constraints(): void
    {
        $this->expectException(QueryException::class);

        // This should trigger a DB-level CHECK constraint failure (empty title)
        CalendarEvent::query()->insert([
            'google_event_id' => 'test-id',
            'title' => '',
            'start_datetime' => now(),
            'end_datetime' => now()->addHour(),
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_database_trim_constraints(): void
    {
        $this->expectException(QueryException::class);

        // This should trigger a DB-level CHECK constraint failure (untrimmed title)
        // We use insert to bypass the model's attribute setters
        CalendarEvent::query()->insert([
            'google_event_id' => 'test-id-2',
            'title' => ' Untrimmed Title ',
            'start_datetime' => now(),
            'end_datetime' => now()->addHour(),
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
