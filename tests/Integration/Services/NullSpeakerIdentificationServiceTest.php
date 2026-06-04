<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\SpeakerProfile;
use App\Services\Preacher\NullSpeakerIdentificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NullSpeakerIdentificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NullSpeakerIdentificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NullSpeakerIdentificationService;
    }

    #[Test]
    public function it_returns_failed_embedding_result(): void
    {
        $result = $this->service->extractEmbedding('path/to/audio.mp3');

        $this->assertFalse($result->success);
        $this->assertEquals('Null provider: speaker identification not configured', $result->errorMessage);
    }

    #[Test]
    public function it_returns_no_match_result(): void
    {
        $result = $this->service->identify('path/to/audio.mp3', new Collection);

        $this->assertFalse($result->matched);
        $this->assertEquals('Null provider: speaker identification not configured', $result->reason);
    }

    #[Test]
    public function it_returns_unchanged_profile_when_updating(): void
    {
        $profile = SpeakerProfile::factory()->create();

        $result = $this->service->updateProfile($profile, [[0.1, 0.2, 0.3]]);

        $this->assertTrue($profile->is($result));
        $this->assertSame($profile, $result);
    }
}
