<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingLogValidationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_validates_processing_id_as_uuid(): void
    {
        $rules = MediaProcessingLog::validationRules();

        // Valid UUID
        $validator = Validator::make(['processing_id' => '550e8400-e29b-41d4-a716-446655440000'], ['processing_id' => $rules['processing_id']]);
        $this->assertFalse($validator->fails(), 'Valid UUID should pass');

        // Invalid UUID
        $validator = Validator::make(['processing_id' => 'not-a-uuid'], ['processing_id' => $rules['processing_id']]);
        $this->assertTrue($validator->fails(), 'Invalid UUID should fail');
    }

    #[Test]
    public function it_validates_processing_id_uniqueness(): void
    {
        $existing = MediaProcessingLog::factory()->create(['processing_id' => '550e8400-e29b-41d4-a716-446655440000']);
        $rules = MediaProcessingLog::validationRules();

        // Duplicate UUID
        $validator = Validator::make(['processing_id' => $existing->processing_id], ['processing_id' => $rules['processing_id']]);
        $this->assertTrue($validator->fails(), 'Duplicate UUID should fail');

        // Same UUID when ignoring the existing log
        $rulesWithIgnore = MediaProcessingLog::validationRules($existing);
        $validator = Validator::make(['processing_id' => $existing->processing_id], ['processing_id' => $rulesWithIgnore['processing_id']]);
        $this->assertFalse($validator->fails(), 'Same UUID should pass when ignoring current log');
    }

    #[Test]
    public function it_validates_date_fields(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $dateFields = ['extracted_date', 'started_at', 'completed_at'];

        foreach ($dateFields as $field) {
            // Valid date
            $validator = Validator::make([$field => '2026-03-17'], [$field => $rules[$field]]);
            $this->assertFalse($validator->fails(), "Valid date for $field should pass");

            // Invalid date
            $validator = Validator::make([$field => 'not-a-date'], [$field => $rules[$field]]);
            $this->assertTrue($validator->fails(), "Invalid date for $field should fail");
        }
    }

    #[Test]
    public function it_validates_enum_fields(): void
    {
        $rules = MediaProcessingLog::validationRules();

        // Valid enum
        $validator = Validator::make(['extracted_service' => 'morning'], ['extracted_service' => $rules['extracted_service']]);
        $this->assertFalse($validator->fails(), 'Valid enum should pass');

        // Invalid enum
        $validator = Validator::make(['extracted_service' => 'invalid-service'], ['extracted_service' => $rules['extracted_service']]);
        $this->assertTrue($validator->fails(), 'Invalid enum should fail');
    }

    #[Test]
    public function it_validates_array_fields(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $arrayFields = ['ai_analysis', 'processing_metadata', 'rms_stats', 'visual_samples', 'song_clusters'];

        foreach ($arrayFields as $field) {
            // Valid array
            $validator = Validator::make([$field => ['key' => 'value']], [$field => $rules[$field]]);
            $this->assertFalse($validator->fails(), "Valid array for $field should pass");

            // Invalid array (string instead of array)
            $validator = Validator::make([$field => 'not-an-array'], [$field => $rules[$field]]);
            $this->assertTrue($validator->fails(), "Invalid array (string) for $field should fail");
        }
    }
}
