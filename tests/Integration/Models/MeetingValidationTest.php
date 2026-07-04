<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingValidationTest extends TestCase
{
    use RefreshDatabase;

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
    public function it_validates_day_length(): void
    {
        $rules = Meeting::validationRules();

        // 75 characters - should pass
        $validDay = str_repeat('a', 75);
        $validator = Validator::make(['day' => $validDay], ['day' => $rules['day']]);
        $this->assertFalse($validator->fails(), '75 characters should pass');

        // 76 characters - should fail
        $invalidDay = str_repeat('a', 76);
        $validator = Validator::make(['day' => $invalidDay], ['day' => $rules['day']]);
        $this->assertTrue($validator->fails(), '76 characters should fail');
        $this->assertEquals('The day field must not be greater than 75 characters.', $validator->errors()->first('day'));
    }

    #[Test]
    public function it_validates_trimmed_text_rule(): void
    {
        $rules = Meeting::validationRules();

        // Leading whitespace
        $validator = Validator::make(['who' => '  Everyone'], ['who' => $rules['who']]);
        $this->assertTrue($validator->fails());
        $this->assertEquals('The who field must not be empty or contain leading or trailing whitespace.', $validator->errors()->first('who'));

        // Trailing whitespace
        $validator = Validator::make(['location' => 'Church '], ['location' => $rules['location']]);
        $this->assertTrue($validator->fails());
        $this->assertEquals('The location field must not be empty or contain leading or trailing whitespace.', $validator->errors()->first('location'));

        // Valid trimmed text
        $validator = Validator::make(['who' => 'Everyone'], ['who' => $rules['who']]);
        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_frequency_if_recurring_is_true(): void
    {
        $rules = Meeting::validationRules();

        // recurring=true, frequency=null -> should fail
        $validator = Validator::make([
            'is_recurring' => true,
            'frequency' => null,
        ], [
            'is_recurring' => $rules['is_recurring'],
            'frequency' => $rules['frequency'],
        ]);
        $this->assertTrue($validator->fails());
        $this->assertEquals('The frequency field is required when is recurring is true.', $validator->errors()->first('frequency'));

        // recurring=false, frequency=null -> should pass
        $validator = Validator::make([
            'is_recurring' => false,
            'frequency' => null,
        ], [
            'is_recurring' => $rules['is_recurring'],
            'frequency' => $rules['frequency'],
        ]);
        $this->assertFalse($validator->fails());
    }
}
