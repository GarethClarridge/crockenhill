<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SpeakerSample;
use Tests\TestCase;

class ModelValidationTest extends TestCase
{
    /**
     * Test that Sermon validation rules are robust.
     */
    public function test_sermon_validation_rules_are_robust(): void
    {
        $rules = Sermon::validationRules();

        $this->assertArrayHasKey('audio_file_path', $rules);
        $this->assertContains('required', $rules['audio_file_path']);
        $this->assertContains('max:255', $rules['audio_file_path']);

        $this->assertArrayHasKey('video_file_path', $rules);
        $this->assertContains('nullable', $rules['video_file_path']);
        $this->assertContains('max:500', $rules['video_file_path']);

        $this->assertArrayHasKey('content_type', $rules);
        $this->assertContains('required', $rules['content_type']);
        $this->assertTrue($this->hasEnumRule($rules['content_type'], \App\Enums\SermonContentType::class));

        $this->assertArrayHasKey('source_type', $rules);
        $this->assertContains('nullable', $rules['source_type']);
        $this->assertTrue($this->hasEnumRule($rules['source_type'], \App\Enums\SermonSourceType::class));

        $this->assertArrayHasKey('service', $rules);
        $this->assertContains('nullable', $rules['service']);
        $this->assertTrue($this->hasEnumRule($rules['service'], \App\Enums\SermonService::class));

        $this->assertArrayHasKey('preacher_source', $rules);
        $this->assertContains('nullable', $rules['preacher_source']);
        $this->assertTrue($this->hasEnumRule($rules['preacher_source'], \App\Enums\PreacherSource::class));

        $this->assertArrayHasKey('preacher_confidence', $rules);
        $this->assertContains('nullable', $rules['preacher_confidence']);
        $this->assertContains('min:0', $rules['preacher_confidence']);
        $this->assertContains('max:1', $rules['preacher_confidence']);
    }

    /**
     * Test that MediaProcessingLog validation rules are robust.
     */
    public function test_media_processing_log_validation_rules_are_robust(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $this->assertArrayHasKey('processing_id', $rules);
        $this->assertContains('required', $rules['processing_id']);
        $this->assertContains('size:36', $rules['processing_id']);

        $this->assertArrayHasKey('processing_type', $rules);
        $this->assertContains('required', $rules['processing_type']);
        $this->assertTrue($this->hasEnumRule($rules['processing_type'], \App\Enums\MediaType::class));

        $this->assertArrayHasKey('status', $rules);
        $this->assertContains('required', $rules['status']);
        $this->assertTrue($this->hasEnumRule($rules['status'], \App\Enums\ProcessingStatus::class));

        $this->assertArrayHasKey('sermon_id', $rules);
        $this->assertContains('exists:sermons,id', $rules['sermon_id']);

        $this->assertArrayHasKey('owner_user_id', $rules);
        $this->assertContains('exists:users,id', $rules['owner_user_id']);

        $this->assertArrayHasKey('church_service_id', $rules);
        $this->assertContains('exists:church_services,id', $rules['church_service_id']);

        $this->assertArrayHasKey('original_filename', $rules);
        $this->assertContains('required', $rules['original_filename']);
        $this->assertContains('max:255', $rules['original_filename']);
    }

    /**
     * Test that SpeakerSample validation rules are robust.
     */
    public function test_speaker_sample_validation_rules_are_robust(): void
    {
        $rules = SpeakerSample::validationRules();

        $this->assertArrayHasKey('speaker_profile_id', $rules);
        $this->assertContains('required', $rules['speaker_profile_id']);
        $this->assertContains('exists:speaker_profiles,id', $rules['speaker_profile_id']);

        $this->assertArrayHasKey('sermon_id', $rules);
        $this->assertContains('exists:sermons,id', $rules['sermon_id']);

        $this->assertArrayHasKey('media_processing_log_id', $rules);
        $this->assertContains('exists:media_processing_logs,id', $rules['media_processing_log_id']);

        $this->assertArrayHasKey('source', $rules);
        $this->assertContains('required', $rules['source']);
        $this->assertTrue($this->hasEnumRule($rules['source'], \App\Enums\SampleSource::class));
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
            if ($rule instanceof \Illuminate\Validation\Rules\Enum) {
                // Use reflection to check the protected "type" property of the Enum rule
                $reflection = new \ReflectionClass($rule);
                $property = $reflection->getProperty('type');
                $property->setAccessible(true);

                if ($property->getValue($rule) === $enumClass) {
                    return true;
                }
            }
        }

        return false;
    }
}
