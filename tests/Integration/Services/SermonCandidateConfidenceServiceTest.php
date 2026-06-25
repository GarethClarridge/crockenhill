<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\Sermon\SermonCandidateConfidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonCandidateConfidenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonCandidateConfidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SermonCandidateConfidenceService;
    }

    #[Test]
    public function it_identifies_a_clear_sermon_candidate_from_the_database(): void
    {
        $log = MediaProcessingLog::factory()->create();

        // One long speech segment (25 mins = 1500s)
        $candidate = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'duration' => 1500.0,
            'start_time' => 1000.0,
        ]);

        // One short speech segment (5 mins = 300s)
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'duration' => 300.0,
            'start_time' => 500.0,
        ]);

        // One long SONG segment (should be ignored)
        LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $log->id,
            'duration' => 2000.0,
        ]);

        $result = $this->service->evaluateForProcessingLog($log);

        $this->assertTrue($result['is_clear']);
        $this->assertSame('clear', $result['reason']);
        $this->assertSame($candidate->id, $result['candidate']?->id);
        $this->assertCount(2, $result['speech_segments']);

        // Verify ordering in speech_segments (by start_time)
        $this->assertEquals(500.0, $result['speech_segments'][0]['start_time']);
        $this->assertEquals(1000.0, $result['speech_segments'][1]['start_time']);
    }

    #[Test]
    public function it_flags_review_when_multiple_qualifying_speech_blocks_exist_in_db(): void
    {
        $log = MediaProcessingLog::factory()->create();

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'duration' => 1500.0,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'duration' => 1600.0,
        ]);

        $result = $this->service->evaluateForProcessingLog($log);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('multiple_qualifying_speech_blocks', $result['reason']);
        $this->assertNull($result['candidate']);
        $this->assertEquals(2, $result['qualifying_segments_count']);
    }

    #[Test]
    public function it_flags_review_when_no_speech_block_is_long_enough(): void
    {
        $log = MediaProcessingLog::factory()->create();

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'duration' => 600.0,
        ]);

        $result = $this->service->evaluateForProcessingLog($log);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('no_qualifying_speech_block', $result['reason']);
        $this->assertNull($result['candidate']);
    }

    #[Test]
    public function it_flags_review_when_ratio_between_longest_segments_is_too_low(): void
    {
        $log = MediaProcessingLog::factory()->create();

        // Longest is 25 mins (1500s) - Qualifying
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'duration' => 1500.0,
        ]);

        // Second longest is 18.3 mins (1100s) - Not qualifying (threshold is 20 mins)
        // 1500 / 1100 = 1.36 (< 1.5 threshold)
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'duration' => 1100.0,
        ]);

        $result = $this->service->evaluateForProcessingLog($log);

        $this->assertFalse($result['is_clear']);
        $this->assertSame('ratio_below_threshold', $result['reason']);
        $this->assertNull($result['candidate']);
    }

    #[Test]
    public function it_only_considers_segments_belonging_to_the_specified_log(): void
    {
        $log1 = MediaProcessingLog::factory()->create();
        $log2 = MediaProcessingLog::factory()->create();

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log1->id,
            'duration' => 1500.0,
        ]);

        // Long segment for DIFFERENT log
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log2->id,
            'duration' => 2000.0,
        ]);

        $result = $this->service->evaluateForProcessingLog($log1);

        $this->assertTrue($result['is_clear']);
        $this->assertSame(1, $result['qualifying_segments_count']);
        $this->assertEquals(0.0, $result['next_longest_duration']);
    }
}
