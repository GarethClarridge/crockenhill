<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingValidationTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_validates_required_fields(): void
    {
        $rules = Meeting::validationRules();
        $validator = Validator::make([], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('slug', $validator->errors()->toArray());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
        $this->assertArrayHasKey('who', $validator->errors()->toArray());
    }

    #[Test]
    public function it_passes_with_minimal_valid_data(): void
    {
        $rules = Meeting::validationRules();
        $data = [
            'slug' => 'test-meeting',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }

    #[Test]
    public function it_validates_slug_format(): void
    {
        $rules = Meeting::validationRules();

        $invalidSlugs = ['Test Meeting', 'test_meeting', 'test/meeting', 'test.meeting'];

        foreach ($invalidSlugs as $slug) {
            $validator = Validator::make(['slug' => $slug, 'type' => MeetingType::Adults->value, 'who' => 'Everyone'], $rules);
            $this->assertTrue($validator->fails(), "Slug '{$slug}' should be invalid.");
            $this->assertArrayHasKey('slug', $validator->errors()->toArray());
        }
    }

    #[Test]
    public function it_validates_enum_values(): void
    {
        $rules = Meeting::validationRules();

        $validator = Validator::make([
            'slug' => 'test-meeting',
            'type' => 'invalid-type',
            'who' => 'Everyone',
            'frequency' => 'invalid-frequency',
            'is_recurring' => true,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
        $this->assertArrayHasKey('frequency', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_date_and_time_formats(): void
    {
        $rules = Meeting::validationRules();

        $data = [
            'slug' => 'test-meeting',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
            'start_time' => 'invalid-time',
            'end_time' => 'invalid-time',
            'meeting_date' => 'invalid-date',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('start_time', $validator->errors()->toArray());
        $this->assertArrayHasKey('end_time', $validator->errors()->toArray());
        $this->assertArrayHasKey('meeting_date', $validator->errors()->toArray());

        // Valid formats
        $validData = [
            'slug' => 'test-meeting',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'meeting_date' => '2023-01-01',
        ];
        $validator = Validator::make($validData, $rules);
        $this->assertFalse($validator->fails(), $validator->errors()->first());

        $validData2 = [
            'slug' => 'test-meeting',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ];
        $validator = Validator::make($validData2, $rules);
        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }

    #[Test]
    public function it_validates_end_time_is_after_start_time(): void
    {
        $rules = Meeting::validationRules();

        $data = [
            'slug' => 'test-meeting',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
            'start_time' => '11:00',
            'end_time' => '10:00',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('end_time', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_frequency_requirement_for_recurring_meetings(): void
    {
        $rules = Meeting::validationRules();

        $data = [
            'slug' => 'test-meeting',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
            'is_recurring' => true,
            'frequency' => null,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('frequency', $validator->errors()->toArray());

        $data['frequency'] = MeetingFrequency::Weekly->value;
        $validator = Validator::make($data, $rules);
        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_validates_slug_uniqueness(): void
    {
        $existingMeeting = Meeting::factory()->create(['slug' => 'existing-slug']);
        $rules = Meeting::validationRules();

        $data = [
            'slug' => 'existing-slug',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
        ];

        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('slug', $validator->errors()->toArray());

        // Should ignore itself
        $rulesWithIgnore = Meeting::validationRules($existingMeeting);
        $validator = Validator::make($data, $rulesWithIgnore);
        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }

    #[Test]
    public function it_validates_page_id_uniqueness(): void
    {
        $page = Page::factory()->create();
        $existingMeeting = Meeting::factory()->create(['page_id' => $page->id]);
        $rules = Meeting::validationRules();

        $data = [
            'slug' => 'new-slug',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
            'page_id' => $page->id,
        ];

        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('page_id', $validator->errors()->toArray());

        // Should ignore itself
        $rulesWithIgnore = Meeting::validationRules($existingMeeting);
        $validator = Validator::make($data, $rulesWithIgnore);
        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }

    #[Test]
    public function it_validates_page_id_exists(): void
    {
        $rules = Meeting::validationRules();

        $data = [
            'slug' => 'test-meeting',
            'type' => MeetingType::Adults->value,
            'who' => 'Everyone',
            'page_id' => 999999,
        ];

        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('page_id', $validator->errors()->toArray());
    }
}
