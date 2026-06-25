<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamSegmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_a_unique_constraint_on_media_processing_log_id_and_segment_index(): void
    {
        $this->assertTrue(
            Schema::hasIndex('livestream_segments', 'livestream_segments_log_index_unique'),
            'The livestream_segments table is missing the unique index on (media_processing_log_id, segment_index)'
        );
    }

    #[Test]
    public function it_prevents_duplicate_segment_index_for_the_same_processing_log(): void
    {
        $log = MediaProcessingLog::factory()->create();

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        // Bypass Eloquent and validation to test database constraint directly
        DB::table('livestream_segments')->insert([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'start_time' => 10.0,
            'end_time' => 20.0,
            'duration' => 10.0,
            'classification' => 'speech',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_factory_keeps_end_time_consistent_when_start_time_and_duration_are_overridden(): void
    {
        $log = MediaProcessingLog::factory()->create();

        // Overriding start_time/duration without end_time must not leave a stale
        // end_time that violates livestream_segments_timing_check (end_time >= start_time).
        $segment = LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'start_time' => 1000.0,
            'duration' => 1500.0,
        ]);

        $this->assertSame(2500.0, (float) $segment->end_time);
    }

    #[Test]
    public function the_factory_respects_an_explicitly_provided_end_time(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $segment = LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'start_time' => 100.0,
            'duration' => 50.0,
            'end_time' => 200.0,
        ]);

        $this->assertSame(200.0, (float) $segment->end_time);
    }

    #[Test]
    public function it_allows_same_segment_index_for_different_processing_logs(): void
    {
        $log1 = MediaProcessingLog::factory()->create();
        $log2 = MediaProcessingLog::factory()->create();

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log1->id,
            'segment_index' => 1,
        ]);

        $segment2 = LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log2->id,
            'segment_index' => 1,
        ]);

        $this->assertDatabaseHas('livestream_segments', [
            'id' => $segment2->id,
            'media_processing_log_id' => $log2->id,
            'segment_index' => 1,
        ]);
    }
}
