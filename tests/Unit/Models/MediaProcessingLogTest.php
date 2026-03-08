<?php

namespace Tests\Unit\Models;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $data = [
            'processing_id' => 'uuid-123',
            'processing_type' => MediaType::Audio,
            'status' => ProcessingStatus::PROCESSING,
            'current_step' => 'initialization',
            'error_message' => 'Something went wrong',
            'original_filename' => 'test.mp3',
            'file_size' => 1024,
            'duration' => 60.5,
            'source_file_path' => 'path/to/file',
            'audio_file_path' => 'path/to/audio',
            'video_file_path' => 'path/to/video',
            'transcript_file_path' => 'path/to/transcript',
            'rms_log_path' => 'path/to/rms',
            'sermon_start_time' => 10.0,
            'sermon_end_time' => 50.0,
            'ai_analysis' => ['foo' => 'bar'],
            'processing_metadata' => ['meta' => 'data'],
            'threshold_method' => 'adaptive',
            'adaptive_threshold' => -20.0,
            'rms_stats' => ['avg' => -25],
            'visual_samples' => ['sample1'],
            'song_clusters' => ['cluster1'],
            'visual_sample_count' => 5,
            'visual_processing_time' => 10.5,
            'sermon_id' => 1,
            'church_service_id' => 2,
            'started_at' => now(),
            'completed_at' => now(),
        ];

        $log = new MediaProcessingLog($data);

        foreach ($data as $key => $value) {
            if (in_array($key, ['started_at', 'completed_at'])) {
                continue;
            }
            $this->assertEquals($value, $log->$key);
        }
    }

    #[Test]
    public function it_casts_attributes_correctly(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => 'processing',
            'ai_analysis' => ['result' => 'ok'],
            'processing_metadata' => ['step' => 1],
            'duration' => '60.5',
            'started_at' => '2024-01-01 10:00:00',
        ]);

        $this->assertInstanceOf(ProcessingStatus::class, $log->status);
        $this->assertEquals(ProcessingStatus::PROCESSING, $log->status);
        $this->assertIsArray($log->ai_analysis);
        $this->assertIsArray($log->processing_metadata);
        $this->assertIsFloat($log->duration);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $log->started_at);
    }

    #[Test]
    public function it_has_relationships(): void
    {
        $sermon = Sermon::factory()->create();
        $churchService = ChurchService::factory()->create();
        $log = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'church_service_id' => $churchService->id,
        ]);
        LivestreamSegment::factory()->count(3)->create(['media_processing_log_id' => $log->id]);

        $this->assertInstanceOf(Sermon::class, $log->sermon);
        $this->assertTrue($log->churchService->is($churchService));
        $this->assertCount(3, $log->segments);
    }

    #[Test]
    public function it_defines_type_scopes(): void
    {
        MediaProcessingLog::factory()->create(['processing_type' => 'audio']);
        MediaProcessingLog::factory()->create(['processing_type' => 'video']);
        MediaProcessingLog::factory()->create(['processing_type' => 'livestream']);

        $this->assertCount(1, MediaProcessingLog::audio()->get());
        $this->assertCount(1, MediaProcessingLog::video()->get());
        $this->assertCount(1, MediaProcessingLog::livestream()->get());
    }

    #[Test]
    public function it_defines_status_scopes(): void
    {
        MediaProcessingLog::factory()->create(['status' => ProcessingStatus::PROCESSING]);
        MediaProcessingLog::factory()->create(['status' => ProcessingStatus::PENDING]);
        MediaProcessingLog::factory()->create(['status' => ProcessingStatus::COMPLETED]);
        MediaProcessingLog::factory()->create(['status' => ProcessingStatus::FAILED]);

        $this->assertCount(1, MediaProcessingLog::processing()->get());
        $this->assertCount(1, MediaProcessingLog::pending()->get());
        $this->assertCount(1, MediaProcessingLog::completed()->get());
        $this->assertCount(1, MediaProcessingLog::failed()->get());
    }

    #[Test]
    public function it_provides_status_helpers(): void
    {
        $log = new MediaProcessingLog(['status' => ProcessingStatus::COMPLETED]);

        $this->assertTrue($log->isComplete());
        $this->assertFalse($log->isFailed());
        $this->assertFalse($log->isProcessing());
        $this->assertFalse($log->isPending());
    }

    #[Test]
    public function it_manages_status_transitions(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::PENDING]);

        $log->markAsProcessing('step1');
        $this->assertEquals(ProcessingStatus::PROCESSING, $log->status);
        $this->assertEquals('step1', $log->current_step);
        $this->assertNotNull($log->started_at);

        $log->markAsCompleted();
        $this->assertEquals(ProcessingStatus::COMPLETED, $log->status);
        $this->assertNotNull($log->completed_at);

        $log->markAsFailed('Error occurred', 'step2');
        $this->assertEquals(ProcessingStatus::FAILED, $log->status);
        $this->assertEquals('Error occurred', $log->error_message);
        $this->assertEquals('step2', $log->current_step);
    }

    #[Test]
    public function it_has_backward_compatible_stored_file_path(): void
    {
        $log = new MediaProcessingLog;
        $log->stored_file_path = 'test-path';

        $this->assertEquals('test-path', $log->source_file_path);
        $this->assertEquals('test-path', $log->stored_file_path);
    }
}
