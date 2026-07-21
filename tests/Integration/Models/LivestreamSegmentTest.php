<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\LivestreamSegmentClassification;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamSegmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $data = [

            'media_processing_log_id' => $log->id,
            'segment_index' => 0,
            'start_time' => 10.5,
            'end_time' => 20.5,
            'duration' => 10.0,
            'classification' => LivestreamSegmentClassification::Speech,
            'avg_rms' => -25.0,
            'peak_rms' => -15.0,
            'is_sermon_candidate' => true,
            'is_sermon_segment' => true,
            'segment_order' => 1,
            'metadata' => ['key' => 'value'],
        ];

        $segment = new LivestreamSegment($data);

        foreach ($data as $key => $value) {
            $this->assertEquals($value, $segment->$key);
        }
    }

    #[Test]
    public function it_casts_attributes_correctly(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $segment = LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'metadata' => ['foo' => 'bar'],
            'start_time' => '10.5',
            'is_sermon_candidate' => 1,
        ]);

        $this->assertIsArray($segment->metadata);
        $this->assertEquals(['foo' => 'bar'], $segment->metadata);
        $this->assertIsFloat($segment->start_time);
        $this->assertIsBool($segment->is_sermon_candidate);
    }

    #[Test]
    public function it_belongs_to_a_processing_log(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $segment = LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
        ]);

        $this->assertInstanceOf(MediaProcessingLog::class, $segment->processingLog);
        $this->assertEquals($log->id, $segment->processingLog->id);
    }

    #[Test]
    public function it_defines_classification_scopes(): void
    {
        $log = MediaProcessingLog::factory()->create();

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'classification' => 'speech',
        ]);
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'classification' => 'song',
        ]);
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 3,
            'classification' => 'silence',
        ]);

        $this->assertCount(1, LivestreamSegment::speech()->get());
        $this->assertCount(1, LivestreamSegment::song()->get());
        $this->assertCount(1, LivestreamSegment::silence()->get());
    }

    #[Test]
    public function it_defines_sermon_candidates_scope(): void
    {
        $log = MediaProcessingLog::factory()->create();

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'is_sermon_candidate' => true,
        ]);
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'is_sermon_candidate' => false,
        ]);

        $this->assertCount(1, LivestreamSegment::sermonCandidates()->get());
    }

    #[Test]
    public function it_defines_duration_range_scope(): void
    {
        $log = MediaProcessingLog::factory()->create();

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'duration' => 10,
        ]);
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'duration' => 20,
        ]);
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 3,
            'duration' => 30,
        ]);

        $this->assertCount(2, LivestreamSegment::byDurationRange(15)->get());
        $this->assertCount(1, LivestreamSegment::byDurationRange(15, 25)->get());
    }

    #[Test]
    public function it_provides_classification_helpers(): void
    {
        $segment = new LivestreamSegment(['classification' => 'speech', 'is_sermon_candidate' => true]);

        $this->assertTrue($segment->isSpeech());
        $this->assertFalse($segment->isSong());
        $this->assertFalse($segment->isSilence());
        $this->assertTrue($segment->isSermonCandidate());
    }

    #[Test]
    public function it_formats_times_correctly(): void
    {
        $segment = new LivestreamSegment([
            'start_time' => 75,      // 01:15
            'end_time' => 3665,     // 01:01:05
            'duration' => 4500,     // 1h 15m 0s
        ]);

        $this->assertEquals('01:15', $segment->getStartTimeFormatted());
        $this->assertEquals('01:01:05', $segment->getEndTimeFormatted());
        $this->assertEquals('1h 15m 0s', $segment->getDurationFormatted());
        $this->assertEquals('01:15 - 01:01:05', $segment->time_range);
    }

    #[Test]
    public function it_provides_segments_summary(): void
    {
        $log = MediaProcessingLog::factory()->create();
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'classification' => 'speech',
            'duration' => 100,
        ]);
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'classification' => 'song',
            'duration' => 50,
        ]);

        $summary = LivestreamSegment::getSegmentsSummary($log->id);

        $this->assertEquals(2, $summary['total_segments']);
        $this->assertEquals(1, $summary['speech_segments']);
        $this->assertEquals(150, $summary['total_duration']);
        $this->assertEquals(100, $summary['longest_speech_duration']);
    }
}
