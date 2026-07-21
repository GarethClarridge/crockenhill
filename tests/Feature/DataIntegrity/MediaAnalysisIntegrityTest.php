<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaAnalysisIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function media_processing_log_rejects_negative_file_size_at_database_level(): void
    {
        $this->skipIfNotMysql();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('media_processing_logs_file_size_check');

        DB::table('media_processing_logs')->insert([
            'processing_id' => Str::uuid()->toString(),
            'processing_type' => 'audio',
            'status' => 'pending',
            'original_filename' => 'test.mp3',
            'file_size' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function livestream_segment_rejects_negative_segment_order_at_database_level(): void
    {
        $this->skipIfNotMysql();

        $log = MediaProcessingLog::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('livestream_segments_segment_order_check');

        DB::table('livestream_segments')->insert([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'start_time' => 0,
            'end_time' => 10,
            'duration' => 10,
            'classification' => 'speech',
            'segment_order' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function media_processing_log_validation_rules_enforce_non_negative_values(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $validator = Validator::make([
            'file_size' => -100,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('file_size', $validator->errors()->toArray());
    }

    #[Test]
    public function livestream_segment_validation_rules_enforce_constraints(): void
    {
        $rules = LivestreamSegment::validationRules();

        $validator = Validator::make([
            'start_time' => 0,
            'end_time' => 10,
            'duration' => 10,
            'segment_order' => -1,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('segment_order', $validator->errors()->toArray());
    }

    private function skipIfNotMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only implemented for MySQL in this project.');
        }
    }
}
