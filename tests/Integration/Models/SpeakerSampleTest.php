<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\SampleSource;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeakerSampleTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_belongs_to_a_speaker_profile(): void
    {
        $profile = SpeakerProfile::factory()->create();
        $sample = SpeakerSample::factory()->create(['speaker_profile_id' => $profile->id]);

        $this->assertTrue($sample->speakerProfile->is($profile));
    }

    #[Test]
    public function it_belongs_to_a_sermon(): void
    {
        $sermon = Sermon::factory()->create();
        $sample = SpeakerSample::factory()->create(['sermon_id' => $sermon->id]);

        $this->assertTrue($sample->sermon->is($sermon));
    }

    #[Test]
    public function it_belongs_to_a_processing_log(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $sample = SpeakerSample::factory()->create(['media_processing_log_id' => $log->id]);

        $this->assertTrue($sample->processingLog->is($log));
    }

    #[Test]
    public function it_casts_attributes_correctly(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $sample = SpeakerSample::factory()->create([
            'embedding' => $embedding,
            'duration_seconds' => '45.5',
            'quality_score' => '0.85',
            'source' => SampleSource::Backfill->value,
            'approved' => 1,
        ]);

        $this->assertIsArray($sample->embedding);
        $this->assertEquals($embedding, $sample->embedding);
        $this->assertIsFloat($sample->duration_seconds);
        $this->assertEquals(45.5, $sample->duration_seconds);
        $this->assertIsFloat($sample->quality_score);
        $this->assertEquals(0.85, $sample->quality_score);
        $this->assertInstanceOf(SampleSource::class, $sample->source);
        $this->assertEquals(SampleSource::Backfill, $sample->source);
        $this->assertIsBool($sample->approved);
        $this->assertTrue($sample->approved);
    }
}
