<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\SongVideo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DurationConstraintTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_rejects_negative_duration_on_media_processing_logs_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('media_processing_logs_duration_check');

        MediaProcessingLog::factory()->create([
            'duration' => -1.0,
        ]);
    }

    #[Test]
    public function it_allows_non_negative_duration_on_media_processing_logs(): void
    {
        $log1 = MediaProcessingLog::factory()->create(['duration' => 0.0]);
        $log2 = MediaProcessingLog::factory()->create(['duration' => 123.45]);
        $log3 = MediaProcessingLog::factory()->create(['duration' => null]);

        $this->assertEquals(0.0, $log1->duration);
        $this->assertEquals(123.45, $log2->duration);
        $this->assertNull($log3->duration);
    }

    #[Test]
    public function it_rejects_negative_duration_on_song_videos_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('song_videos_duration_check');

        SongVideo::factory()->create([
            'duration' => -1.0,
        ]);
    }

    #[Test]
    public function it_allows_non_negative_duration_on_song_videos(): void
    {
        $video1 = SongVideo::factory()->create(['duration' => 0.0]);
        $video2 = SongVideo::factory()->create(['duration' => 45.67]);
        $video3 = SongVideo::factory()->create(['duration' => null]);

        $this->assertEquals(0.0, $video1->duration);
        $this->assertEquals(45.67, $video2->duration);
        $this->assertNull($video3->duration);
    }

    #[Test]
    public function it_rejects_negative_sermon_start_time_on_media_processing_logs(): void
    {
        // The DB constraint fires when both times are provided and either is negative.
        // A negative start_time with a null end_time is allowed at the DB level
        // (application-level validation covers that case — see it_validates_media_processing_log_rules_at_application_level).
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('media_processing_logs_sermon_time_range_check');

        MediaProcessingLog::factory()->create([
            'sermon_start_time' => -1.0,
            'sermon_end_time' => 50.0,
        ]);
    }

    #[Test]
    public function it_rejects_sermon_end_time_before_start_time_on_media_processing_logs(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('media_processing_logs_sermon_time_range_check');

        MediaProcessingLog::factory()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 50.0,
        ]);
    }

    #[Test]
    public function it_rejects_negative_start_time_on_livestream_segments(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('livestream_segments_timing_check');

        LivestreamSegment::factory()->create([
            'start_time' => -1.0,
        ]);
    }

    #[Test]
    public function it_rejects_end_time_before_start_time_on_livestream_segments(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('livestream_segments_timing_check');

        LivestreamSegment::factory()->create([
            'start_time' => 10.0,
            'end_time' => 5.0,
        ]);
    }

    #[Test]
    public function it_validates_media_processing_log_rules_at_application_level(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $this->assertTrue(Validator::make(['duration' => 100], $rules)->passes());
        $this->assertTrue(Validator::make(['sermon_start_time' => 10, 'sermon_end_time' => 20], $rules)->passes());
        $this->assertFalse(Validator::make(['duration' => -1], $rules)->passes());
        $this->assertFalse(Validator::make(['sermon_start_time' => -1], $rules)->passes());
        $this->assertFalse(Validator::make(['sermon_start_time' => 20, 'sermon_end_time' => 10], $rules)->passes());

        // Additional fields synchronized with schema
        $this->assertTrue(Validator::make(['attempt_count' => 0], $rules)->passes());
        $this->assertTrue(Validator::make(['attempt_count' => 65535], $rules)->passes());
        $this->assertFalse(Validator::make(['attempt_count' => -1], $rules)->passes());
        $this->assertFalse(Validator::make(['attempt_count' => 65536], $rules)->passes());

        $this->assertTrue(Validator::make(['dedup_key' => str_repeat('a', 128)], $rules)->passes());
        $this->assertFalse(Validator::make(['dedup_key' => str_repeat('a', 129)], $rules)->passes());

        $this->assertTrue(Validator::make(['current_step' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['current_step' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['source_file_path' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['source_file_path' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['audio_file_path' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['audio_file_path' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['video_file_path' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['video_file_path' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['transcript_file_path' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['transcript_file_path' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['enhanced_audio_file_path' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['enhanced_audio_file_path' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['rms_log_path' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['rms_log_path' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['threshold_method' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['threshold_method' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['adaptive_threshold' => -42.5], $rules)->passes());
        $this->assertTrue(Validator::make(['adaptive_threshold' => 0.0], $rules)->passes());
        $this->assertFalse(Validator::make(['adaptive_threshold' => 'not-a-number'], $rules)->passes());

        $this->assertTrue(Validator::make(['queue_name' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['queue_name' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['job_id' => str_repeat('a', 255)], $rules)->passes());
        $this->assertFalse(Validator::make(['job_id' => str_repeat('a', 256)], $rules)->passes());

        $this->assertTrue(Validator::make(['is_degraded_completion' => true], $rules)->passes());
        $this->assertTrue(Validator::make(['is_degraded_completion' => false], $rules)->passes());
        $this->assertFalse(Validator::make(['is_degraded_completion' => 'maybe'], $rules)->passes());
    }

    #[Test]
    public function it_validates_song_video_rules_at_application_level(): void
    {
        $rules = array_intersect_key(SongVideo::validationRules(), array_flip(['duration']));

        $this->assertTrue(Validator::make(['duration' => 100], $rules)->passes());
        $this->assertFalse(Validator::make(['duration' => -1], $rules)->passes());
    }

    #[Test]
    public function it_validates_livestream_segment_rules_at_application_level(): void
    {
        $rules = LivestreamSegment::validationRules();

        $this->assertTrue(Validator::make(['start_time' => 0, 'end_time' => 10, 'duration' => 10], $rules)->passes());
        $this->assertFalse(Validator::make(['start_time' => -1, 'end_time' => 10, 'duration' => 11], $rules)->passes());
        $this->assertFalse(Validator::make(['start_time' => 10, 'end_time' => 5, 'duration' => 0], $rules)->passes());
        $this->assertFalse(Validator::make(['start_time' => 0, 'end_time' => 10, 'duration' => -1], $rules)->passes());
    }
}
