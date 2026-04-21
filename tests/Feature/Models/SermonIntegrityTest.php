<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\SermonContentType;
use App\Enums\SermonSourceType;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string, mixed>
     */
    private function validSermonData(): array
    {
        return [
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'audio_file_path' => 'audio.mp3',
            'date' => '2025-01-01',
            'content_type' => SermonContentType::Sermon->value,
            'source_type' => SermonSourceType::Manual->value,
            'preacher' => 'Mark Drury',
        ];
    }

    #[Test]
    public function sermon_audio_file_path_cannot_be_empty(): void
    {
        $rules = Sermon::validationRules();
        $data = $this->validSermonData();
        $data['audio_file_path'] = '';

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails(), 'Validation should fail for empty audio_file_path');
        $this->assertArrayHasKey('audio_file_path', $validator->errors()->toArray());
    }

    #[Test]
    public function sermon_video_file_path_cannot_exceed_500_chars(): void
    {
        $rules = Sermon::validationRules();
        $data = $this->validSermonData();
        $data['video_file_path'] = str_repeat('a', 501);

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails(), 'Validation should fail for too long video_file_path');
        $this->assertArrayHasKey('video_file_path', $validator->errors()->toArray());
    }

    #[Test]
    public function sermon_preacher_confidence_must_be_between_0_and_1(): void
    {
        $rules = Sermon::validationRules();
        $data = $this->validSermonData();

        $data['preacher_confidence'] = 1.1;
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails(), 'Validation should fail for preacher_confidence > 1');
        $this->assertArrayHasKey('preacher_confidence', $validator->errors()->toArray());

        $data['preacher_confidence'] = -0.1;
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails(), 'Validation should fail for preacher_confidence < 0');
        $this->assertArrayHasKey('preacher_confidence', $validator->errors()->toArray());
    }

    #[Test]
    public function sermon_enum_fields_are_validated(): void
    {
        $rules = Sermon::validationRules();
        $data = $this->validSermonData();

        $data['service'] = 'invalid-service';
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails(), 'Validation should fail for invalid service enum');
        $this->assertArrayHasKey('service', $validator->errors()->toArray());

        $data['service'] = null;
        $data['content_type'] = 'invalid-type';
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails(), 'Validation should fail for invalid content_type enum');
        $this->assertArrayHasKey('content_type', $validator->errors()->toArray());

        $data['content_type'] = SermonContentType::Sermon->value;
        $data['source_type'] = 'invalid-source';
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails(), 'Validation should fail for invalid source_type enum');
        $this->assertArrayHasKey('source_type', $validator->errors()->toArray());
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
