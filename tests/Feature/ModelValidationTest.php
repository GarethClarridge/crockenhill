<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaType;
use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Enums\ProcessingStatus;
use App\Enums\SampleSource;
use App\Enums\SermonContentType;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Models\Meeting;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Unique;
use Tests\TestCase;

class ModelValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that Sermon validation rules are robust and actually work.
     */
    public function test_sermon_validation_rules_are_robust(): void
    {
        $rules = Sermon::validationRules();

        $this->assertArrayHasKey('slug', $rules);
        $this->assertContains('required', $rules['slug']);
        $this->assertContains('max:255', $rules['slug']);
        $this->assertContains('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $rules['slug']);

        $this->assertArrayHasKey('audio_file_path', $rules);
        $this->assertContains('nullable', $rules['audio_file_path']);
        $this->assertContains('max:255', $rules['audio_file_path']);

        $this->assertArrayHasKey('video_file_path', $rules);
        $this->assertContains('nullable', $rules['video_file_path']);
        $this->assertContains('max:500', $rules['video_file_path']);

        $this->assertArrayHasKey('content_type', $rules);
        $this->assertContains('required', $rules['content_type']);
        $this->assertTrue($this->hasEnumRule($rules['content_type'], SermonContentType::class));

        $this->assertArrayHasKey('preacher_confidence', $rules);
        $this->assertContains('nullable', $rules['preacher_confidence']);
        $this->assertContains('min:0', $rules['preacher_confidence']);
        $this->assertContains('max:1', $rules['preacher_confidence']);

        $this->assertArrayHasKey('segment_start_time', $rules);
        $this->assertContains('nullable', $rules['segment_start_time']);
        $this->assertContains('min:0', $rules['segment_start_time']);

        $this->assertArrayHasKey('segment_end_time', $rules);
        $this->assertContains('nullable', $rules['segment_end_time']);
        $this->assertContains('gte:segment_start_time', $rules['segment_end_time']);

        // Functional test: invalid content type fails
        $data = Sermon::factory()->raw(['content_type' => 'invalid-type']);
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content_type', $validator->errors()->toArray());

        // Functional test: invalid confidence fails
        $data = Sermon::factory()->raw(['preacher_confidence' => 1.5]);
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('preacher_confidence', $validator->errors()->toArray());

        // Functional test: valid data passes
        $data = Sermon::factory()->raw([
            'audio_file_path' => 'sermons/test.mp3',
            'content_type' => 'sermon',
            'preacher_confidence' => 0.85,
        ]);
        $validator = Validator::make($data, $rules);
        $this->assertFalse($validator->fails(), print_r($validator->errors()->all(), true));
    }

    /**
     * Test that MediaProcessingLog validation rules are robust and actually work.
     */
    public function test_media_processing_log_validation_rules_are_robust(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $this->assertArrayHasKey('processing_id', $rules);
        $this->assertContains('required', $rules['processing_id']);
        $this->assertContains('size:36', $rules['processing_id']);

        $this->assertArrayHasKey('processing_type', $rules);
        $this->assertContains('required', $rules['processing_type']);
        $this->assertTrue($this->hasEnumRule($rules['processing_type'], MediaType::class));

        $this->assertArrayHasKey('status', $rules);
        $this->assertContains('required', $rules['status']);
        $this->assertTrue($this->hasEnumRule($rules['status'], ProcessingStatus::class));

        // Functional test: invalid status fails
        $data = MediaProcessingLog::factory()->raw(['status' => 'invalid-status']);
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());

        // Functional test: valid data passes
        $data = MediaProcessingLog::factory()->raw([
            'processing_id' => Str::uuid()->toString(),
            'processing_type' => 'audio',
            'status' => 'pending',
            'original_filename' => 'test.mp3',
        ]);
        $validator = Validator::make($data, $rules);
        $this->assertFalse($validator->fails(), print_r($validator->errors()->all(), true));
    }

    /**
     * Test that InboundEmail validation rules are robust and actually work.
     */
    public function test_inbound_email_validation_rules_are_robust(): void
    {
        $rules = InboundEmail::validationRules();

        $this->assertArrayHasKey('message_id', $rules);
        $this->assertContains('required', $rules['message_id']);
        $this->assertContains('max:512', $rules['message_id']);

        $this->assertArrayHasKey('from', $rules);
        $this->assertContains('required', $rules['from']);
        $this->assertContains('max:255', $rules['from']);

        $this->assertArrayHasKey('subject', $rules);
        $this->assertContains('required', $rules['subject']);
        $this->assertContains('max:255', $rules['subject']);

        // Functional test: unique message_id fails
        InboundEmail::factory()->create(['message_id' => 'duplicate@example.com']);
        $data = InboundEmail::factory()->raw(['message_id' => 'duplicate@example.com']);
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('message_id', $validator->errors()->toArray());

        // Functional test: missing from fails
        $data = InboundEmail::factory()->raw(['from' => '']);
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('from', $validator->errors()->toArray());

        // Functional test: valid data passes
        $data = InboundEmail::factory()->raw(['message_id' => 'unique@example.com']);
        $validator = Validator::make($data, $rules);
        $this->assertFalse($validator->fails(), print_r($validator->errors()->all(), true));
    }

    /**
     * Test that Meeting validation rules are robust and actually work.
     */
    public function test_meeting_validation_rules_are_robust(): void
    {
        $rules = Meeting::validationRules();

        $this->assertArrayHasKey('slug', $rules);
        $this->assertContains('required', $rules['slug']);
        $this->assertContains('max:255', $rules['slug']);
        $this->assertContains('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $rules['slug']);

        $this->assertArrayHasKey('type', $rules);
        $this->assertContains('required', $rules['type']);
        $this->assertTrue($this->hasEnumRule($rules['type'], MeetingType::class));

        $this->assertArrayHasKey('frequency', $rules);
        $this->assertContains('nullable', $rules['frequency']);
        $this->assertTrue($this->hasEnumRule($rules['frequency'], MeetingFrequency::class));
    }

    /**
     * Test that SpeakerSample validation rules are robust and actually work.
     */
    public function test_speaker_sample_validation_rules_are_robust(): void
    {
        $rules = SpeakerSample::validationRules();

        $this->assertArrayHasKey('speaker_profile_id', $rules);
        $this->assertContains('required', $rules['speaker_profile_id']);

        $this->assertArrayHasKey('source', $rules);
        $this->assertContains('required', $rules['source']);
        $this->assertTrue($this->hasEnumRule($rules['source'], SampleSource::class));

        // Functional test: invalid source fails
        $data = SpeakerSample::factory()->raw(['source' => 'invalid-source']);
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());

        // Functional test: valid data passes (assuming profile exists in DB via factory)
        $profile = SpeakerProfile::factory()->create();
        $data = SpeakerSample::factory()->raw([
            'speaker_profile_id' => $profile->id,
            'source' => 'manual_upload',
            'duration_seconds' => 10.5,
        ]);
        $validator = Validator::make($data, $rules);
        $this->assertFalse($validator->fails(), print_r($validator->errors()->all(), true));
    }

    /**
     * Helper to check if a validation rule contains a unique rule.
     */
    private function hasUniqueRule(array|string $rules, string $table, string $column): bool
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $rule) {
            if ($rule instanceof Unique) {
                return true;
            }
            if (is_string($rule) && str_starts_with($rule, 'unique:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper to check if a validation rule contains a Rule::enum() rule.
     */
    private function hasEnumRule(array|string $rules, string $enumClass): bool
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $rule) {
            if ($rule instanceof Enum) {
                // Determine if this rule targets the expected enum class by testing
                // it against a known case from that enum. This avoids brittle
                // reflection on protected internal properties like 'type'.
                $case = $enumClass::cases()[0] ?? null;

                if ($case && $rule->passes('attribute', $case)) {
                    return true;
                }
            }
        }

        return false;
    }
}
