<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Preacher;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeakerProfileTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_belongs_to_a_preacher(): void
    {
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        $this->assertTrue($profile->preacher->is($preacher));
    }

    #[Test]
    public function it_has_many_samples(): void
    {
        $profile = SpeakerProfile::factory()->create();
        SpeakerSample::factory()->count(3)->create(['speaker_profile_id' => $profile->id]);

        $this->assertCount(3, $profile->samples);
        $this->assertInstanceOf(SpeakerSample::class, $profile->samples->first());
    }

    #[Test]
    public function it_has_an_active_scope(): void
    {
        SpeakerProfile::factory()->create(['is_active' => true]);
        SpeakerProfile::factory()->inactive()->create();

        $this->assertEquals(1, SpeakerProfile::active()->count());
        $this->assertTrue(SpeakerProfile::active()->first()->is_active);
    }

    #[Test]
    public function it_returns_effective_accept_threshold_from_override(): void
    {
        $profile = SpeakerProfile::factory()->create(['accept_threshold' => 0.88]);

        $this->assertEquals(0.88, $profile->getEffectiveAcceptThreshold());
    }

    #[Test]
    public function it_returns_effective_accept_threshold_from_config_fallback(): void
    {
        config(['media-processing.speaker_identification.accept_threshold' => 0.77]);
        $profile = SpeakerProfile::factory()->create(['accept_threshold' => null]);

        $this->assertEquals(0.77, $profile->getEffectiveAcceptThreshold());
    }

    #[Test]
    public function it_returns_effective_margin_threshold_from_override(): void
    {
        $profile = SpeakerProfile::factory()->create(['margin_threshold' => 0.12]);

        $this->assertEquals(0.12, $profile->getEffectiveMarginThreshold());
    }

    #[Test]
    public function it_returns_effective_margin_threshold_from_config_fallback(): void
    {
        config(['media-processing.speaker_identification.margin_threshold' => 0.08]);
        $profile = SpeakerProfile::factory()->create(['margin_threshold' => null]);

        $this->assertEquals(0.08, $profile->getEffectiveMarginThreshold());
    }

    #[Test]
    public function it_casts_attributes_correctly(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $profile = SpeakerProfile::factory()->create([
            'centroid_embedding' => $embedding,
            'sample_count' => '5',
            'quality_score' => '0.95',
            'accept_threshold' => '0.8',
            'margin_threshold' => '0.1',
            'is_active' => 1,
        ]);

        $this->assertIsArray($profile->centroid_embedding);
        $this->assertEquals($embedding, $profile->centroid_embedding);
        $this->assertIsInt($profile->sample_count);
        $this->assertEquals(5, $profile->sample_count);
        $this->assertIsFloat($profile->quality_score);
        $this->assertEquals(0.95, $profile->quality_score);
        $this->assertIsFloat($profile->accept_threshold);
        $this->assertEquals(0.8, $profile->accept_threshold);
        $this->assertIsFloat($profile->margin_threshold);
        $this->assertEquals(0.1, $profile->margin_threshold);
        $this->assertIsBool($profile->is_active);
        $this->assertTrue($profile->is_active);
    }
}
