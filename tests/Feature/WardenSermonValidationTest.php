<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WardenSermonValidationTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_rejects_a_non_existent_livestream_processing_id(): void
    {
        $rules = Sermon::validationRules();

        $validator = Validator::make([
            'livestream_processing_id' => '00000000-0000-0000-0000-000000000000',
        ], [
            'livestream_processing_id' => $rules['livestream_processing_id'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('livestream_processing_id', $validator->errors()->toArray());
        $this->assertEquals(
            'The selected livestream processing id is invalid.',
            $validator->errors()->first('livestream_processing_id')
        );
    }

    #[Test]
    public function it_rejects_a_malformed_livestream_processing_id(): void
    {
        $rules = Sermon::validationRules();

        $validator = Validator::make([
            'livestream_processing_id' => 'too-short',
        ], [
            'livestream_processing_id' => $rules['livestream_processing_id'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('livestream_processing_id', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_a_valid_existing_livestream_processing_id(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_id' => '12345678-1234-1234-1234-1234567890ab',
        ]);

        $rules = Sermon::validationRules();

        $validator = Validator::make([
            'livestream_processing_id' => $log->processing_id,
        ], [
            'livestream_processing_id' => $rules['livestream_processing_id'],
        ]);

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_accepts_a_null_livestream_processing_id(): void
    {
        $rules = Sermon::validationRules();

        $validator = Validator::make([
            'livestream_processing_id' => null,
        ], [
            'livestream_processing_id' => $rules['livestream_processing_id'],
        ]);

        $this->assertFalse($validator->fails());
    }
}
