<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Enums\SampleSource;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeakerIdentityContractsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function first_or_create_reuses_the_existing_speaker_profile_identity_row(): void
    {
        $preacher = Preacher::factory()->create();
        $existing = SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'sample_count' => 3,
        ]);

        $resolved = SpeakerProfile::query()->firstOrCreate(
            [
                'preacher_id' => $preacher->id,
                'provider' => 'resemblyzer',
                'model_version' => 'v1.0',
            ],
            [
                'centroid_embedding' => array_fill(0, 256, 0.0),
                'sample_count' => 99,
                'is_active' => false,
            ]
        );

        $this->assertTrue($resolved->is($existing));
        $this->assertSame(1, SpeakerProfile::query()->count());
        $this->assertSame(3, $resolved->fresh()->sample_count);
    }

    #[Test]
    public function update_or_create_updates_the_existing_speaker_sample_identity_row(): void
    {
        $profile = SpeakerProfile::factory()->create();
        $sermon = Sermon::factory()->create();
        $sample = SpeakerSample::factory()->create([
            'speaker_profile_id' => $profile->id,
            'sermon_id' => $sermon->id,
            'source' => SampleSource::Backfill->value,
            'approved' => false,
            'duration_seconds' => 45.0,
        ]);

        $resolved = SpeakerSample::query()->updateOrCreate(
            [
                'speaker_profile_id' => $profile->id,
                'sermon_id' => $sermon->id,
                'source' => SampleSource::Backfill->value,
            ],
            [
                'media_processing_log_id' => null,
                'embedding' => array_fill(0, 256, 0.5),
                'duration_seconds' => 90.0,
                'quality_score' => 0.95,
                'approved' => true,
            ]
        );

        $this->assertSame($sample->id, $resolved->id);
        $this->assertSame(1, SpeakerSample::query()->count());
        $this->assertTrue($resolved->fresh()->approved);
        $this->assertSame(90.0, $resolved->fresh()->duration_seconds);
    }

    #[Test]
    public function the_database_rejects_duplicate_speaker_sample_identity_rows(): void
    {
        $profile = SpeakerProfile::factory()->create();
        $sermon = Sermon::factory()->create();

        SpeakerSample::factory()->create([
            'speaker_profile_id' => $profile->id,
            'sermon_id' => $sermon->id,
            'source' => SampleSource::Backfill->value,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        SpeakerSample::factory()->create([
            'speaker_profile_id' => $profile->id,
            'sermon_id' => $sermon->id,
            'source' => SampleSource::Backfill->value,
        ]);
    }
}
