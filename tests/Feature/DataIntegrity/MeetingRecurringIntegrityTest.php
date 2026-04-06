<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\Meeting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingRecurringIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_rejects_recurring_meeting_without_frequency_at_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only implemented for MySQL in this project.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_recurring_frequency_check');

        // Use DB directly to bypass any model-level validation if it existed
        DB::table('meetings')->insert([
            'slug' => 'integrity-test-meeting',
            'type' => 'Occasional',
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => false,
            'is_recurring' => true,
            'frequency' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_allows_recurring_meeting_with_frequency(): void
    {
        $meeting = Meeting::factory()->create([
            'is_recurring' => true,
            'frequency' => \App\Enums\MeetingFrequency::Weekly,
        ]);

        $this->assertTrue($meeting->is_recurring);
        $this->assertEquals(\App\Enums\MeetingFrequency::Weekly, $meeting->frequency);
    }

    #[Test]
    public function it_allows_non_recurring_meeting_without_frequency(): void
    {
        $meeting = Meeting::factory()->create([
            'is_recurring' => false,
            'frequency' => null,
        ]);

        $this->assertFalse($meeting->is_recurring);
        $this->assertNull($meeting->frequency);
    }

    #[Test]
    public function update_meeting_request_validates_recurring_frequency(): void
    {
        $request = new \App\Http\Requests\UpdateMeetingRequest;
        $rules = $request->rules();

        $validator = \Illuminate\Support\Facades\Validator::make([
            'slug' => 'test-meeting',
            'type' => \App\Enums\MeetingType::OCCASIONAL->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
            'is_recurring' => true,
            'frequency' => null,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('frequency', $validator->errors()->toArray());
    }

    #[Test]
    public function update_meeting_request_validates_time_formats(): void
    {
        $request = new \App\Http\Requests\UpdateMeetingRequest;
        $rules = $request->rules();

        // Test valid format with seconds
        $validator = \Illuminate\Support\Facades\Validator::make([
            'slug' => 'test-meeting',
            'type' => \App\Enums\MeetingType::OCCASIONAL->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
            'start_time' => '10:30:00',
        ], $rules);

        $this->assertFalse($validator->fails());

        // Test valid format H:i
        $validator = \Illuminate\Support\Facades\Validator::make([
            'slug' => 'test-meeting',
            'type' => \App\Enums\MeetingType::OCCASIONAL->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
            'start_time' => '10:30',
        ], $rules);

        $this->assertFalse($validator->fails());

        // Test end_time after or equal to start_time
        $validator = \Illuminate\Support\Facades\Validator::make([
            'slug' => 'test-meeting',
            'type' => \App\Enums\MeetingType::OCCASIONAL->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
            'start_time' => '10:30',
            'end_time' => '11:30',
        ], $rules);

        $this->assertFalse($validator->fails());

        // Test end_time before start_time
        $validator = \Illuminate\Support\Facades\Validator::make([
            'slug' => 'test-meeting',
            'type' => \App\Enums\MeetingType::OCCASIONAL->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
            'start_time' => '11:30',
            'end_time' => '10:30',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('end_time', $validator->errors()->toArray());
    }
}
