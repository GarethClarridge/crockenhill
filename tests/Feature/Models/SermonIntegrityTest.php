<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_audio_file_path_cannot_be_empty(): void
    {
        $rules = Sermon::validationRules();

        $validator = Validator::make([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'preacher' => 'Mark Drury',
            'audio_file_path' => '',
        ], $rules);

        // Currently this passes because audio_file_path is not in the validationRules
        $this->assertTrue($validator->fails(), 'Validation should fail for empty audio_file_path');
        $this->assertArrayHasKey('audio_file_path', $validator->errors()->toArray());
    }

    #[Test]
    public function sermon_video_file_path_cannot_exceed_500_chars(): void
    {
        $rules = Sermon::validationRules();
        $longPath = str_repeat('a', 501);

        $validator = Validator::make([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'preacher' => 'Mark Drury',
            'audio_file_path' => 'audio.mp3',
            'video_file_path' => $longPath,
        ], $rules);

        // Currently this passes because video_file_path is not in the validationRules
        $this->assertTrue($validator->fails(), 'Validation should fail for too long video_file_path');
        $this->assertArrayHasKey('video_file_path', $validator->errors()->toArray());
    }

    #[Test]
    public function sermon_preacher_confidence_must_be_between_0_and_1(): void
    {
        $rules = Sermon::validationRules();

        $validator = Validator::make([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'preacher' => 'Mark Drury',
            'audio_file_path' => 'audio.mp3',
            'preacher_confidence' => 1.1,
        ], $rules);

        // Currently this passes because preacher_confidence is in rules but without max:1
        $this->assertTrue($validator->fails(), 'Validation should fail for preacher_confidence > 1');

        $validator = Validator::make([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'preacher' => 'Mark Drury',
            'audio_file_path' => 'audio.mp3',
            'preacher_confidence' => -0.1,
        ], $rules);

        $this->assertTrue($validator->fails(), 'Validation should fail for preacher_confidence < 0');
    }

    #[Test]
    public function sermon_enum_fields_are_validated(): void
    {
        $rules = Sermon::validationRules();

        $validator = Validator::make([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'preacher' => 'Mark Drury',
            'audio_file_path' => 'audio.mp3',
            'service' => 'invalid-service',
        ], $rules);

        // Currently this passes because service is not in validationRules
        $this->assertTrue($validator->fails(), 'Validation should fail for invalid service enum');
    }

    #[Test]
    public function sermon_audio_file_path_database_constraint(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database constraint is MySQL specific');
        }

        $this->expectException(\Illuminate\Database\QueryException::class);

        Sermon::factory()->create([
            'audio_file_path' => '',
        ]);
    }
}
