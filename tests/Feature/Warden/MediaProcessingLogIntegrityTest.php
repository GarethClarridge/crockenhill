<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\MediaProcessingLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingLogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_time_range_is_rejected_by_database_when_end_is_before_start()
    {
        $this->expectException(QueryException::class);

        DB::table('media_processing_logs')->insert(
            array_merge(
                MediaProcessingLog::factory()->make()->toArray(),
                [
                    'sermon_start_time' => 100.0,
                    'sermon_end_time' => 50.0,
                    'ai_analysis' => null,
                    'processing_metadata' => null,
                    'rms_stats' => null,
                ]
            )
        );
    }

    #[Test]
    public function sermon_time_range_is_accepted_by_database_when_end_is_after_start()
    {
        DB::table('media_processing_logs')->insert(
            array_merge(
                MediaProcessingLog::factory()->make()->toArray(),
                [
                    'sermon_start_time' => 50.0,
                    'sermon_end_time' => 100.0,
                    'ai_analysis' => null,
                    'processing_metadata' => null,
                    'rms_stats' => null,
                ]
            )
        );

        $this->assertDatabaseHas('media_processing_logs', [
            'sermon_start_time' => 50.0,
            'sermon_end_time' => 100.0,
        ]);
    }

    #[Test]
    public function sermon_time_range_is_accepted_by_database_when_start_is_null()
    {
        DB::table('media_processing_logs')->insert(
            array_merge(
                MediaProcessingLog::factory()->make()->toArray(),
                [
                    'sermon_start_time' => null,
                    'sermon_end_time' => 100.0,
                    'ai_analysis' => null,
                    'processing_metadata' => null,
                    'rms_stats' => null,
                ]
            )
        );

        $this->assertDatabaseHas('media_processing_logs', [
            'sermon_start_time' => null,
            'sermon_end_time' => 100.0,
        ]);
    }

    #[Test]
    public function original_filename_is_automatically_trimmed()
    {
        $log = MediaProcessingLog::factory()->create([
            'original_filename' => '  Trimmed Filename.mp3  ',
        ]);

        $this->assertEquals('Trimmed Filename.mp3', $log->fresh()->original_filename);
    }

    #[Test]
    public function empty_original_filename_is_rejected_by_database()
    {
        $this->expectException(QueryException::class);

        DB::table('media_processing_logs')->insert(
            array_merge(
                MediaProcessingLog::factory()->make()->toArray(),
                [
                    'original_filename' => '',
                    'ai_analysis' => null,
                    'processing_metadata' => null,
                    'rms_stats' => null,
                ]
            )
        );
    }

    #[Test]
    public function untrimmed_original_filename_is_rejected_by_database()
    {
        $this->expectException(QueryException::class);

        DB::table('media_processing_logs')->insert(
            array_merge(
                MediaProcessingLog::factory()->make()->toArray(),
                [
                    'original_filename' => ' Untrimmed.mp3 ',
                    'ai_analysis' => null,
                    'processing_metadata' => null,
                    'rms_stats' => null,
                ]
            )
        );
    }
}
