<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Sermon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonValidationIntegrityTest extends TestCase
{
    #[Test]
    public function it_validates_video_quality_status_enum(): void
    {
        $rules = Sermon::validationRules();

        // Valid values
        foreach (SermonVideoQualityStatus::cases() as $status) {
            $validator = Validator::make(['video_quality_status' => $status->value], ['video_quality_status' => $rules['video_quality_status']]);
            $this->assertFalse($validator->fails(), "Failed for valid status: {$status->value}");
        }

        // Invalid value
        $validator = Validator::make(['video_quality_status' => 'invalid-status'], ['video_quality_status' => $rules['video_quality_status']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_validates_video_quality_reason_length(): void
    {
        $rules = Sermon::validationRules();

        // Valid length (64 characters)
        $validReason = str_repeat('a', 64);
        $validator = Validator::make(['video_quality_reason' => $validReason], ['video_quality_reason' => $rules['video_quality_reason']]);
        $this->assertFalse($validator->fails());

        // Invalid length (65 characters)
        $invalidReason = str_repeat('a', 65);
        $validator = Validator::make(['video_quality_reason' => $invalidReason], ['video_quality_reason' => $rules['video_quality_reason']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_validates_video_visibility_override_enum(): void
    {
        $rules = Sermon::validationRules();

        // Valid values
        foreach (SermonVideoVisibilityOverride::cases() as $override) {
            $validator = Validator::make(['video_visibility_override' => $override->value], ['video_visibility_override' => $rules['video_visibility_override']]);
            $this->assertFalse($validator->fails(), "Failed for valid override: {$override->value}");
        }

        // Invalid value
        $validator = Validator::make(['video_visibility_override' => 'invalid-override'], ['video_visibility_override' => $rules['video_visibility_override']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_validates_video_quality_assessed_at_date(): void
    {
        $rules = Sermon::validationRules();

        // Valid date
        $validator = Validator::make(['video_quality_assessed_at' => '2025-01-01 10:00:00'], ['video_quality_assessed_at' => $rules['video_quality_assessed_at']]);
        $this->assertFalse($validator->fails());

        // Invalid date
        $validator = Validator::make(['video_quality_assessed_at' => 'not-a-date'], ['video_quality_assessed_at' => $rules['video_quality_assessed_at']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_validates_thumbnail_generated_at_date(): void
    {
        $rules = Sermon::validationRules();

        // Valid date
        $validator = Validator::make(['thumbnail_generated_at' => '2025-01-01 10:00:00'], ['thumbnail_generated_at' => $rules['thumbnail_generated_at']]);
        $this->assertFalse($validator->fails());

        // Invalid date
        $validator = Validator::make(['thumbnail_generated_at' => 'not-a-date'], ['thumbnail_generated_at' => $rules['thumbnail_generated_at']]);
        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_validates_thumbnail_metadata_array(): void
    {
        $rules = Sermon::validationRules();

        // Valid array
        $validator = Validator::make(['thumbnail_metadata' => ['key' => 'value']], ['thumbnail_metadata' => $rules['thumbnail_metadata']]);
        $this->assertFalse($validator->fails());

        // Invalid (not an array)
        $validator = Validator::make(['thumbnail_metadata' => 'not-an-array'], ['thumbnail_metadata' => $rules['thumbnail_metadata']]);
        $this->assertTrue($validator->fails());
    }
}
