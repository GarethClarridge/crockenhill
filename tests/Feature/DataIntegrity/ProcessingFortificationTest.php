<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\MediaProcessingLog;
use App\Models\LivestreamSegment;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingFortificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function service_section_validation_rules_enforce_integer_bounding(): void
    {
        $rules = ServiceSection::validationRules();

        $this->assertTrue(Validator::make(['media_processing_log_id' => 2147483648], ['media_processing_log_id' => $rules['media_processing_log_id']])->fails());

        $validator = Validator::make(['media_processing_log_id' => 2147483648], ['media_processing_log_id' => $rules['media_processing_log_id']]);
        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('2147483647', $validator->errors()->first('media_processing_log_id'));

        $validator = Validator::make(['section_order' => 2147483648], ['section_order' => $rules['section_order']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function service_section_validation_rules_enforce_time_offset_bounding(): void
    {
        $rules = ServiceSection::validationRules();

        $validator = Validator::make(['start_time' => 10000000], ['start_time' => $rules['start_time']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['end_time' => 10000000], ['end_time' => $rules['end_time']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['duration' => 10000000], ['duration' => $rules['duration']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function service_section_validation_rules_enforce_string_lengths(): void
    {
        $rules = ServiceSection::validationRules();

        $validator = Validator::make(['title' => str_repeat('a', 256)], ['title' => $rules['title']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['extracted_video_path' => str_repeat('a', 256)], ['extracted_video_path' => $rules['extracted_video_path']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function livestream_segment_validation_rules_enforce_bounding(): void
    {
        $rules = LivestreamSegment::validationRules();

        $validator = Validator::make(['visual_sample_count' => 2147483648], ['visual_sample_count' => $rules['visual_sample_count']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['segment_order' => 2147483648], ['segment_order' => $rules['segment_order']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['start_time' => 10000000], ['start_time' => $rules['start_time']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function media_processing_log_validation_rules_enforce_bounding(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $validator = Validator::make(['duration' => 10000000], ['duration' => $rules['duration']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['visual_sample_count' => 2147483648], ['visual_sample_count' => $rules['visual_sample_count']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['sermon_id' => 2147483648], ['sermon_id' => $rules['sermon_id']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function media_processing_log_casts_are_correct(): void
    {
        $log = new MediaProcessingLog();
        $log->sermon_id = "123";
        $log->owner_user_id = "456";
        $log->church_service_id = "789";

        $this->assertSame(123, $log->sermon_id);
        $this->assertSame(456, $log->owner_user_id);
        $this->assertSame(789, $log->church_service_id);
    }
}
