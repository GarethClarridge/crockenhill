<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingFortificationTest extends TestCase
{
    #[Test]
    public function service_section_validation_rules_match_column_bounds(): void
    {
        $rules = ServiceSection::validationRules();

        $this->assertNotContains('max:2147483647', $rules['media_processing_log_id']);
        $this->assertNotContains('max:2147483647', $rules['church_service_item_id']);
        $this->assertNotContains('max:2147483647', $rules['matched_item_id']);
        $this->assertNotContains('max:2147483647', $rules['expected_item_id']);

        $this->assertContains('max:4294967295', $rules['section_order']);
        $this->assertValidationPasses($rules['section_order'], 'section_order', 4294967295);
        $this->assertValidationFails($rules['section_order'], 'section_order', 4294967296);

        $this->assertContains('max:4294967295', $rules['published_sermon_id']);
        $this->assertValidationPasses($rules['published_sermon_id'], 'published_sermon_id', 4294967295);
        $this->assertValidationFails($rules['published_sermon_id'], 'published_sermon_id', 4294967296);
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

        $this->assertNotContains('max:2147483647', $rules['media_processing_log_id']);

        $this->assertValidationPasses($rules['segment_index'], 'segment_index', 65535);
        $this->assertValidationFails($rules['segment_index'], 'segment_index', 65536);

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

        $this->assertContains('max:4294967295', $rules['sermon_id']);
        $this->assertValidationPasses($rules['sermon_id'], 'sermon_id', 4294967295);
        $this->assertValidationFails($rules['sermon_id'], 'sermon_id', 4294967296);

        $this->assertContains('max:4294967295', $rules['owner_user_id']);
        $this->assertValidationPasses($rules['owner_user_id'], 'owner_user_id', 4294967295);
        $this->assertValidationFails($rules['owner_user_id'], 'owner_user_id', 4294967296);

        $this->assertNotContains('max:2147483647', $rules['church_service_id']);
        $this->assertValidationPasses($rules['church_service_id'], 'church_service_id', 4294967295);

        $validator = Validator::make(['duration' => 10000000], ['duration' => $rules['duration']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['visual_sample_count' => 2147483648], ['visual_sample_count' => $rules['visual_sample_count']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function media_processing_log_casts_are_correct(): void
    {
        $log = new MediaProcessingLog;
        $log->sermon_id = '123';
        $log->owner_user_id = '456';
        $log->church_service_id = '789';

        $this->assertSame(123, $log->sermon_id);
        $this->assertSame(456, $log->owner_user_id);
        $this->assertSame(789, $log->church_service_id);
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function assertValidationPasses(array $rules, string $field, mixed $value): void
    {
        $validator = Validator::make([$field => $value], [$field => $this->rulesWithoutExists($rules)]);

        $this->assertTrue($validator->passes(), $validator->errors()->first($field));
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function assertValidationFails(array $rules, string $field, mixed $value): void
    {
        $validator = Validator::make([$field => $value], [$field => $this->rulesWithoutExists($rules)]);

        $this->assertTrue($validator->fails());
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private function rulesWithoutExists(array $rules): array
    {
        return array_values(array_filter(
            $rules,
            static fn (mixed $rule): bool => ! is_string($rule) || ! str_starts_with($rule, 'exists:')
        ));
    }
}
