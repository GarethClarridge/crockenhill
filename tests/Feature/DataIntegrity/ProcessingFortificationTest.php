<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\ServiceSection;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingFortificationTest extends TestCase
{
    private const UNSIGNED_INTEGER_MAX = 4294967295;

    private const SIGNED_INTEGER_MAX = 2147483647;

    #[Test]
    public function service_section_validation_rules_match_column_bounds(): void
    {
        $rules = ServiceSection::validationRules();

        $this->assertRuleHasNoMaximum($rules['media_processing_log_id']);
        $this->assertRuleHasNoMaximum($rules['church_service_item_id']);
        $this->assertRuleHasNoMaximum($rules['matched_item_id']);
        $this->assertRuleHasNoMaximum($rules['expected_item_id']);

        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $rules['section_order']);
        $this->assertValidationPasses($rules['section_order'], 'section_order', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['section_order'], 'section_order', self::UNSIGNED_INTEGER_MAX + 1);

        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $rules['published_sermon_id']);
        $this->assertValidationPasses($rules['published_sermon_id'], 'published_sermon_id', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['published_sermon_id'], 'published_sermon_id', self::UNSIGNED_INTEGER_MAX + 1);
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

        $this->assertRuleHasNoMaximum($rules['media_processing_log_id']);

        $this->assertValidationPasses($rules['segment_index'], 'segment_index', 65535);
        $this->assertValidationFails($rules['segment_index'], 'segment_index', 65536);

        $validator = Validator::make(['visual_sample_count' => self::SIGNED_INTEGER_MAX + 1], ['visual_sample_count' => $rules['visual_sample_count']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['segment_order' => self::SIGNED_INTEGER_MAX + 1], ['segment_order' => $rules['segment_order']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['start_time' => 10000000], ['start_time' => $rules['start_time']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function media_processing_log_validation_rules_enforce_bounding(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $rules['sermon_id']);
        $this->assertValidationPasses($rules['sermon_id'], 'sermon_id', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['sermon_id'], 'sermon_id', self::UNSIGNED_INTEGER_MAX + 1);

        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $rules['owner_user_id']);
        $this->assertValidationPasses($rules['owner_user_id'], 'owner_user_id', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['owner_user_id'], 'owner_user_id', self::UNSIGNED_INTEGER_MAX + 1);

        $this->assertRuleHasNoMaximum($rules['church_service_id']);

        $validator = Validator::make(['duration' => 10000000], ['duration' => $rules['duration']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['visual_sample_count' => self::SIGNED_INTEGER_MAX + 1], ['visual_sample_count' => $rules['visual_sample_count']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function speaker_profile_validation_rules_match_column_bounds(): void
    {
        $rules = \App\Models\SpeakerProfile::validationRules();

        $this->assertContains('max:'.self::SIGNED_INTEGER_MAX, $rules['preacher_id']);
        $this->assertValidationPasses($rules['preacher_id'], 'preacher_id', self::SIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['preacher_id'], 'preacher_id', self::SIGNED_INTEGER_MAX + 1);

        $validator = Validator::make(['provider' => str_repeat('a', 51)], ['provider' => $rules['provider']]);
        $this->assertTrue($validator->fails());

        $validator = Validator::make(['model_version' => str_repeat('a', 51)], ['model_version' => $rules['model_version']]);
        $this->assertTrue($validator->fails());

        $this->assertContains('max:'.self::SIGNED_INTEGER_MAX, $rules['sample_count']);
        $this->assertValidationPasses($rules['sample_count'], 'sample_count', self::SIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['sample_count'], 'sample_count', self::SIGNED_INTEGER_MAX + 1);
    }

    #[Test]
    public function speaker_sample_validation_rules_match_column_bounds(): void
    {
        $rules = \App\Models\SpeakerSample::validationRules();

        $this->assertContains('max:'.self::SIGNED_INTEGER_MAX, $rules['speaker_profile_id']);
        $this->assertValidationPasses($rules['speaker_profile_id'], 'speaker_profile_id', self::SIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['speaker_profile_id'], 'speaker_profile_id', self::SIGNED_INTEGER_MAX + 1);

        $this->assertContains('max:'.self::SIGNED_INTEGER_MAX, $rules['sermon_id']);
        $this->assertValidationPasses($rules['sermon_id'], 'sermon_id', self::SIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['sermon_id'], 'sermon_id', self::SIGNED_INTEGER_MAX + 1);

        $this->assertContains('max:'.self::SIGNED_INTEGER_MAX, $rules['media_processing_log_id']);
        $this->assertValidationPasses($rules['media_processing_log_id'], 'media_processing_log_id', self::SIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['media_processing_log_id'], 'media_processing_log_id', self::SIGNED_INTEGER_MAX + 1);

        $validator = Validator::make(['duration_seconds' => 10000000], ['duration_seconds' => $rules['duration_seconds']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function speaker_model_casts_are_correct(): void
    {
        $profile = new \App\Models\SpeakerProfile;
        $profile->preacher_id = '123';
        $this->assertSame(123, $profile->preacher_id);

        $sample = new \App\Models\SpeakerSample;
        $sample->speaker_profile_id = '123';
        $sample->sermon_id = '456';
        $sample->media_processing_log_id = '789';
        $this->assertSame(123, $sample->speaker_profile_id);
        $this->assertSame(456, $sample->sermon_id);
        $this->assertSame(789, $sample->media_processing_log_id);
    }

    #[Test]
    public function related_model_validation_rules_match_column_bounds(): void
    {
        $churchServiceRules = ChurchService::validationRules();
        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $churchServiceRules['manual_reviewed_by_user_id']);
        $this->assertValidationPasses($churchServiceRules['manual_reviewed_by_user_id'], 'manual_reviewed_by_user_id', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($churchServiceRules['manual_reviewed_by_user_id'], 'manual_reviewed_by_user_id', self::UNSIGNED_INTEGER_MAX + 1);

        $churchServiceItemRules = ChurchServiceItem::validationRules();
        $this->assertRuleHasNoMaximum($churchServiceItemRules['church_service_id']);
        $this->assertRuleHasNoMaximum($churchServiceItemRules['song_id']);
        $this->assertRuleHasNoMaximum($churchServiceItemRules['livestream_service_section_id']);
        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $churchServiceItemRules['position']);
        $this->assertValidationPasses($churchServiceItemRules['position'], 'position', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($churchServiceItemRules['position'], 'position', self::UNSIGNED_INTEGER_MAX + 1);

        $meetingRules = Meeting::validationRules();
        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $meetingRules['page_id']);
        $this->assertValidationPasses($meetingRules['page_id'], 'page_id', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($meetingRules['page_id'], 'page_id', self::UNSIGNED_INTEGER_MAX + 1);

        $pageRules = Page::validationRules();
        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $pageRules['sort_order']);
        $this->assertValidationPasses($pageRules['sort_order'], 'sort_order', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($pageRules['sort_order'], 'sort_order', self::UNSIGNED_INTEGER_MAX + 1);
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

    /**
     * @param  list<mixed>  $rules
     */
    private function assertRuleHasNoMaximum(array $rules): void
    {
        $this->assertFalse(
            collect($rules)->contains(static fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'max:'))
        );
    }
}
