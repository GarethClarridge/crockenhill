<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\SampleSource;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeakerValidationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function speaker_profile_validation_rules_accept_valid_data(): void
    {
        $preacher = Preacher::factory()->create();

        $data = [
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'centroid_embedding' => array_fill(0, 256, 0.1),
            'sample_count' => 10,
            'quality_score' => 0.95,
            'accept_threshold' => 0.75,
            'margin_threshold' => 0.10,
            'is_active' => true,
        ];

        $validator = Validator::make($data, SpeakerProfile::validationRules());

        $this->assertFalse($validator->fails(), 'Validation should have passed for valid SpeakerProfile data.');
    }

    #[Test]
    public function speaker_profile_validation_rules_reject_invalid_data(): void
    {
        $cases = [
            'missing preacher_id' => [
                'data' => ['provider' => 'resemblyzer', 'model_version' => 'v1.0', 'centroid_embedding' => []],
                'field' => 'preacher_id',
            ],
            'preacher_id does not exist' => [
                'data' => ['preacher_id' => 9999, 'provider' => 'resemblyzer', 'model_version' => 'v1.0', 'centroid_embedding' => []],
                'field' => 'preacher_id',
            ],
            'provider too long' => [
                'data' => ['preacher_id' => 1, 'provider' => str_repeat('a', 51), 'model_version' => 'v1.0', 'centroid_embedding' => []],
                'field' => 'provider',
            ],
            'model_version too long' => [
                'data' => ['preacher_id' => 1, 'provider' => 'resemblyzer', 'model_version' => str_repeat('v', 51), 'centroid_embedding' => []],
                'field' => 'model_version',
            ],
            'negative sample_count' => [
                'data' => ['preacher_id' => 1, 'provider' => 'resemblyzer', 'model_version' => 'v1.0', 'centroid_embedding' => [], 'sample_count' => -1],
                'field' => 'sample_count',
            ],
            'quality_score > 1' => [
                'data' => ['preacher_id' => 1, 'provider' => 'resemblyzer', 'model_version' => 'v1.0', 'centroid_embedding' => [], 'quality_score' => 1.1],
                'field' => 'quality_score',
            ],
            'accept_threshold < 0' => [
                'data' => ['preacher_id' => 1, 'provider' => 'resemblyzer', 'model_version' => 'v1.0', 'centroid_embedding' => [], 'accept_threshold' => -0.1],
                'field' => 'accept_threshold',
            ],
        ];

        foreach ($cases as $name => $case) {
            $validator = Validator::make($case['data'], SpeakerProfile::validationRules());
            $this->assertTrue($validator->fails(), "Validation should have failed for: $name");
            $this->assertArrayHasKey($case['field'], $validator->errors()->toArray(), "Error should have been for field: {$case['field']}");
        }
    }

    #[Test]
    public function speaker_sample_validation_rules_accept_valid_data(): void
    {
        $profile = SpeakerProfile::factory()->create();
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create();

        $data = [
            'speaker_profile_id' => $profile->id,
            'sermon_id' => $sermon->id,
            'media_processing_log_id' => $log->id,
            'embedding' => array_fill(0, 256, 0.1),
            'duration_seconds' => 30.5,
            'quality_score' => 0.99,
            'source' => SampleSource::UploadAuto->value,
            'approved' => true,
        ];

        $validator = Validator::make($data, SpeakerSample::validationRules());

        $this->assertFalse($validator->fails(), 'Validation should have passed for valid SpeakerSample data.');
    }

    #[Test]
    public function speaker_sample_validation_rules_reject_invalid_data(): void
    {
        $cases = [
            'speaker_profile_id does not exist' => [
                'data' => ['speaker_profile_id' => 9999, 'embedding' => [], 'duration_seconds' => 10, 'source' => SampleSource::UploadAuto->value],
                'field' => 'speaker_profile_id',
            ],
            'sermon_id does not exist' => [
                'data' => ['speaker_profile_id' => 1, 'sermon_id' => 9999, 'embedding' => [], 'duration_seconds' => 10, 'source' => SampleSource::UploadAuto->value],
                'field' => 'sermon_id',
            ],
            'negative duration' => [
                'data' => ['speaker_profile_id' => 1, 'embedding' => [], 'duration_seconds' => -1, 'source' => SampleSource::UploadAuto->value],
                'field' => 'duration_seconds',
            ],
            'duration too large' => [
                'data' => ['speaker_profile_id' => 1, 'embedding' => [], 'duration_seconds' => 10000000, 'source' => SampleSource::UploadAuto->value],
                'field' => 'duration_seconds',
            ],
            'invalid source' => [
                'data' => ['speaker_profile_id' => 1, 'embedding' => [], 'duration_seconds' => 10, 'source' => 'invalid'],
                'field' => 'source',
            ],
        ];

        foreach ($cases as $name => $case) {
            $validator = Validator::make($case['data'], SpeakerSample::validationRules());
            $this->assertTrue($validator->fails(), "Validation should have failed for: $name");
            $this->assertArrayHasKey($case['field'], $validator->errors()->toArray(), "Error should have been for field: {$case['field']}");
        }
    }
}
