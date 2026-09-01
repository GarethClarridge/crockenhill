<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\LivestreamSegment;
use App\Services\Sermon\SermonCandidateConfidenceService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonCandidateConfidenceServiceTest extends TestCase
{
    private SermonCandidateConfidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SermonCandidateConfidenceService;
    }

    #[Test]
    public function it_returns_clear_when_exactly_one_speech_block_meets_both_thresholds(): void
    {
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 11,
                'classification' => 'speech',
                'start_time' => 0.0,
                'end_time' => 1500.0,
                'duration' => 1500.0,
            ]),
            LivestreamSegment::factory()->make([
                'id' => 12,
                'classification' => 'speech',
                'start_time' => 1600.0,
                'end_time' => 2300.0,
                'duration' => 700.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments);

        $this->assertTrue($result['is_clear']);
        $this->assertSame('clear', $result['reason']);
        $this->assertSame(11, $result['candidate']?->id);
    }

    #[Test]
    public function it_flags_review_when_multiple_speech_blocks_meet_twenty_minutes(): void
    {
        $segments = new Collection([
            LivestreamSegment::factory()->make(['id' => 21, 'classification' => 'speech', 'duration' => 1500.0]),
            LivestreamSegment::factory()->make(['id' => 22, 'classification' => 'speech', 'duration' => 1300.0]),
        ]);

        $result = $this->service->evaluate($segments);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('multiple_qualifying_speech_blocks', $result['reason']);
        $this->assertNull($result['candidate']);
    }

    #[Test]
    public function it_flags_review_when_no_speech_block_reaches_twenty_minutes(): void
    {
        $segments = new Collection([
            LivestreamSegment::factory()->make(['id' => 31, 'classification' => 'speech', 'duration' => 600.0]),
            LivestreamSegment::factory()->make(['id' => 32, 'classification' => 'speech', 'duration' => 500.0]),
        ]);

        $result = $this->service->evaluate($segments);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('no_qualifying_speech_block', $result['reason']);
        $this->assertNull($result['candidate']);
    }

    #[Test]
    public function it_flags_review_when_ratio_threshold_is_not_met(): void
    {
        $segments = new Collection([
            LivestreamSegment::factory()->make(['id' => 41, 'classification' => 'speech', 'duration' => 1320.0]),
            LivestreamSegment::factory()->make(['id' => 42, 'classification' => 'speech', 'duration' => 900.0]),
        ]);

        $result = $this->service->evaluate($segments);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('ratio_below_threshold', $result['reason']);
        $this->assertNull($result['candidate']);
    }

    #[Test]
    public function it_flags_review_when_the_sole_candidate_exceeds_the_maximum_duration(): void
    {
        // A 65-minute block with no competitor (F10): the 1.5x ratio guard cannot fire, so the
        // duration cap is the only thing standing between under-segmentation and a wrong extract.
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 51,
                'classification' => 'speech',
                'start_time' => 0.0,
                'end_time' => 3916.0,
                'duration' => 3916.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('candidate_exceeds_maximum_duration', $result['reason']);
        $this->assertNull($result['candidate']);
    }

    #[Test]
    public function it_keeps_a_sole_candidate_clear_when_within_the_maximum_duration(): void
    {
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 61,
                'classification' => 'speech',
                'start_time' => 0.0,
                'end_time' => 1800.0,
                'duration' => 1800.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments);

        $this->assertTrue($result['is_clear']);
        $this->assertSame('clear', $result['reason']);
        $this->assertSame(61, $result['candidate']?->id);
    }

    #[Test]
    public function it_accepts_a_short_sermon_when_the_recording_is_the_sermon_alone(): void
    {
        // 2025-11-02-evening in shape: a 15.3-minute capture that starts at the
        // sermon, so its single speech block covers the whole recording. Under the
        // whole-service floor this was rejected as "no qualifying speech block".
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 71,
                'classification' => 'speech',
                'start_time' => 12.0,
                'end_time' => 908.0,
                'duration' => 896.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments, recordingDuration: 920.0);

        $this->assertTrue($result['is_clear']);
        $this->assertSame('clear', $result['reason']);
        $this->assertSame(71, $result['candidate']?->id);
        $this->assertTrue($result['sermon_only_recording']);
        $this->assertSame(600.0, $result['minimum_duration_applied']);
    }

    #[Test]
    public function it_still_requires_twenty_minutes_when_the_recording_holds_a_whole_service(): void
    {
        // The same 896-second block inside a 50-minute service recording is one
        // element among many, so the whole-service floor still governs it.
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 81,
                'classification' => 'speech',
                'start_time' => 600.0,
                'end_time' => 1496.0,
                'duration' => 896.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments, recordingDuration: 3000.0);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('no_qualifying_speech_block', $result['reason']);
        $this->assertFalse($result['sermon_only_recording']);
        $this->assertSame(1200.0, $result['minimum_duration_applied']);
    }

    #[Test]
    public function it_rejects_a_short_non_sermon_item_recorded_on_its_own(): void
    {
        // 2023-07-16-morning: 405 seconds of children's talk covering its whole
        // recording. Full coverage must not by itself make something a sermon.
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 91,
                'classification' => 'speech',
                'start_time' => 0.0,
                'end_time' => 405.0,
                'duration' => 405.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments, recordingDuration: 405.2);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('no_qualifying_speech_block', $result['reason']);
        $this->assertTrue($result['sermon_only_recording']);
        $this->assertSame(600.0, $result['minimum_duration_applied']);
    }

    #[Test]
    public function it_treats_an_unknown_recording_duration_as_a_whole_service(): void
    {
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 101,
                'classification' => 'speech',
                'start_time' => 0.0,
                'end_time' => 896.0,
                'duration' => 896.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments);

        $this->assertFalse($result['is_clear']);
        $this->assertFalse($result['sermon_only_recording']);
        $this->assertSame(1200.0, $result['minimum_duration_applied']);
    }
}
