<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\MediaProcessingLog;
use App\Models\SongVideo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('media_processing_logs_timing_check');

        MediaProcessingLog::factory()->create([
            'sermon_start_time' => -1.0,
        ]);
    }

    #[Test]
    public function it_rejects_sermon_end_time_before_start_time_on_media_processing_logs(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('media_processing_logs_timing_check');

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

        \App\Models\LivestreamSegment::factory()->create([
            'start_time' => -1.0,
        ]);
    }

    #[Test]
    public function it_rejects_end_time_before_start_time_on_livestream_segments(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('livestream_segments_timing_check');

        \App\Models\LivestreamSegment::factory()->create([
            'start_time' => 10.0,
            'end_time' => 5.0,
        ]);
    }
}
