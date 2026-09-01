<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonService;
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
    public function it_accepts_a_typical_length_sermon_when_the_recording_is_the_sermon_alone(): void
    {
        // 2026-05-24-evening in shape: a 28-minute capture that starts at the sermon,
        // so its single speech block covers the whole recording. The whole-service
        // floor would have taken this on length alone had the file been shorter.
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 71,
                'classification' => 'speech',
                'start_time' => 41.0,
                'end_time' => 1693.0,
                'duration' => 1652.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments, 1694.7, SermonService::Evening);

        $this->assertTrue($result['is_clear']);
        $this->assertSame('clear', $result['reason']);
        $this->assertSame(71, $result['candidate']?->id);
        $this->assertTrue($result['sermon_only_recording']);
        $this->assertSame(900.0, $result['typical_minimum_duration']);
    }

    #[Test]
    public function it_accepts_a_short_evening_sermon_that_still_reaches_the_typical_length(): void
    {
        // 15m20s of evening sermon: under the old 20-minute floor, over what an
        // evening sermon usually runs to.
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 72,
                'classification' => 'speech',
                'start_time' => 8.0,
                'end_time' => 928.0,
                'duration' => 920.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments, 940.0, SermonService::Evening);

        $this->assertTrue($result['is_clear']);
        $this->assertSame(72, $result['candidate']?->id);
    }

    #[Test]
    public function it_reviews_rather_than_rejects_an_unusually_short_sermon(): void
    {
        // A carol service may carry an eight-minute sermon. That is legitimate, and
        // it is also the shape a non-sermon item takes, so a person decides.
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 73,
                'classification' => 'speech',
                'start_time' => 6.0,
                'end_time' => 486.0,
                'duration' => 480.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments, 490.0, SermonService::Evening);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('sermon_shorter_than_typical', $result['reason']);
        $this->assertNull($result['candidate']);
        $this->assertTrue($result['sermon_only_recording']);
        $this->assertSame(900.0, $result['typical_minimum_duration']);
    }

    #[Test]
    public function it_holds_a_morning_sermon_to_a_longer_typical_length_than_an_evening_one(): void
    {
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 74,
                'classification' => 'speech',
                'start_time' => 10.0,
                'end_time' => 1024.0,
                'duration' => 1014.0,
            ]),
        ]);

        $evening = $this->service->evaluate($segments, 1030.0, SermonService::Evening);
        $morning = $this->service->evaluate($segments, 1030.0, SermonService::Morning);

        $this->assertTrue($evening['is_clear']);
        $this->assertFalse($morning['is_clear']);
        $this->assertSame('sermon_shorter_than_typical', $morning['reason']);
        $this->assertSame(1500.0, $morning['typical_minimum_duration']);
    }

    #[Test]
    public function it_resolves_an_unnamed_service_towards_review(): void
    {
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 75,
                'classification' => 'speech',
                'start_time' => 10.0,
                'end_time' => 1024.0,
                'duration' => 1014.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments, 1030.0);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('sermon_shorter_than_typical', $result['reason']);
        $this->assertSame(1500.0, $result['typical_minimum_duration']);
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

        $result = $this->service->evaluate($segments, 3000.0, SermonService::Evening);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('no_qualifying_speech_block', $result['reason']);
        $this->assertFalse($result['sermon_only_recording']);
        $this->assertSame(1200.0, $result['minimum_duration_applied']);
        $this->assertNull($result['typical_minimum_duration']);
    }

    #[Test]
    public function it_sends_a_short_lone_item_to_review_rather_than_publishing_it(): void
    {
        // 2023-07-16-morning: 405 seconds of children's talk covering its whole
        // recording. Full coverage must never by itself make something a sermon.
        $segments = new Collection([
            LivestreamSegment::factory()->make([
                'id' => 91,
                'classification' => 'speech',
                'start_time' => 0.0,
                'end_time' => 405.0,
                'duration' => 405.0,
            ]),
        ]);

        $result = $this->service->evaluate($segments, 405.2, SermonService::Morning);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('sermon_shorter_than_typical', $result['reason']);
        $this->assertNull($result['candidate']);
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
