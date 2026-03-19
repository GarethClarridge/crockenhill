<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Preacher;
use App\Models\SpeakerProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeakerProfileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_calculates_effective_accept_threshold(): void
    {
        $profile = SpeakerProfile::factory()->create(['accept_threshold' => 0.85]);
        $this->assertEquals(0.85, $profile->getEffectiveAcceptThreshold());

        $profileWithDefault = SpeakerProfile::factory()->create(['accept_threshold' => null]);
        config(['media-processing.speaker_identification.accept_threshold' => 0.75]);
        $this->assertEquals(0.75, $profileWithDefault->getEffectiveAcceptThreshold());
    }

    #[Test]
    public function it_calculates_effective_margin_threshold(): void
    {
        $profile = SpeakerProfile::factory()->create(['margin_threshold' => 0.15]);
        $this->assertEquals(0.15, $profile->getEffectiveMarginThreshold());

        $profileWithDefault = SpeakerProfile::factory()->create(['margin_threshold' => null]);
        config(['media-processing.speaker_identification.margin_threshold' => 0.10]);
        $this->assertEquals(0.10, $profileWithDefault->getEffectiveMarginThreshold());
    }

    #[Test]
    public function it_enforces_unique_constraint_on_preacher_provider_and_version(): void
    {
        $preacher = Preacher::factory()->create();

        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);
    }

    #[Test]
    public function it_allows_different_providers_for_same_preacher(): void
    {
        $preacher = Preacher::factory()->create();

        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);

        $profile2 = SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'other-provider',
            'model_version' => 'v1.0',
        ]);

        $this->assertDatabaseHas('speaker_profiles', ['id' => $profile2->id]);
    }

    #[Test]
    public function it_allows_different_versions_for_same_preacher_and_provider(): void
    {
        $preacher = Preacher::factory()->create();

        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);

        $profile2 = SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v2.0',
        ]);

        $this->assertDatabaseHas('speaker_profiles', ['id' => $profile2->id]);
    }

    #[Test]
    public function it_can_be_created_using_the_inactive_factory_state(): void
    {
        $profile = SpeakerProfile::factory()->inactive()->create();

        $this->assertFalse($profile->is_active);
    }
}
