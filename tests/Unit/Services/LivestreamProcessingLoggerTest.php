<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Models\LivestreamSegment;
use App\Services\LivestreamProcessingLogger;
use App\Services\ProcessingReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LivestreamProcessingLoggerTest extends TestCase
{
    use DatabaseTransactions;

    private LivestreamProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new LivestreamProcessingLogger;
        Log::spy();
    }

    public function test_log_processing_step()
    {
        $processingId = 'test-processing-id';
        $step = 'video_analysis';
        $context = ['file_size' => 1024, 'duration' => 3600];

        $this->logger->logProcessingStep($processingId, $step, $context);

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                "Livestream processing step: {$step}",
                \Mockery::on(function ($data) use ($processingId, $step, $context) {
                    return $data['processing_id'] === $processingId
                        && $data['step'] === $step
                        && $data['context'] === $context
                        && isset($data['timestamp'])
                        && isset($data['memory_usage'])
                        && isset($data['memory_peak']);
                })
            );
    }

    public function test_log_error()
    {
        $processingId = 'test-processing-id';
        $step = 'segmentation';
        $exception = new \Exception('Test error message');

        $this->logger->logError($processingId, $step, $exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                "Livestream processing error in step: {$step}",
                \Mockery::on(function ($data) use ($processingId, $step, $exception) {
                    return $data['processing_id'] === $processingId
                        && $data['step'] === $step
                        && $data['error_message'] === $exception->getMessage()
                        && isset($data['stack_trace'])
                        && isset($data['file'])
                        && isset($data['line'])
                        && isset($data['timestamp']);
                })
            );
    }

    public function test_log_warning()
    {
        $processingId = 'test-processing-id';
        $step = 'video_extraction';
        $message = 'Video quality may be reduced';
        $context = ['quality' => 'low'];

        $this->logger->logWarning($processingId, $step, $message, $context);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                "Livestream processing warning in step: {$step}",
                \Mockery::on(function ($data) use ($processingId, $step, $message, $context) {
                    return $data['processing_id'] === $processingId
                        && $data['step'] === $step
                        && $data['warning_message'] === $message
                        && $data['context'] === $context
                        && isset($data['timestamp']);
                })
            );
    }

    public function test_log_performance_metrics()
    {
        $processingId = 'test-processing-id';
        $step = 'ffmpeg_analysis';
        $executionTime = 123.456;
        $metrics = ['segments_found' => 5, 'file_processed' => true];

        $this->logger->logPerformanceMetrics($processingId, $step, $executionTime, $metrics);

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                "Livestream processing performance metrics: {$step}",
                \Mockery::on(function ($data) use ($processingId, $step, $metrics) {
                    return $data['processing_id'] === $processingId
                        && $data['step'] === $step
                        && $data['execution_time_seconds'] === 123.456
                        && $data['metrics'] === $metrics
                        && isset($data['memory_usage_mb'])
                        && isset($data['memory_peak_mb'])
                        && isset($data['timestamp']);
                })
            );
    }

    public function test_generate_processing_report()
    {
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-id',
            'status' => 'completed',
            'original_filename' => 'test-video.mp4',
            'file_size' => 1073741824, // 1GB
            'duration' => 3600.0, // duration in seconds as float
            'sermon_id' => null,
            'completed_at' => now(),
        ]);

        $segments = LivestreamSegment::factory()->count(5)->create([
            'media_processing_log_id' => $processing->id,
        ]);

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $processing->id,
            'classification' => 'song',
            'duration' => 180.0,
        ]);

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $processing->id,
            'classification' => 'speech',
            'duration' => 1800.0,
            'is_sermon_candidate' => true,
        ]);

        $report = $this->logger->generateProcessingReport('test-processing-id');

        $this->assertInstanceOf(ProcessingReport::class, $report);

        $data = $report->toArray();
        $this->assertEquals('test-processing-id', $data['processing_id']);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals('test-video.mp4', $data['original_filename']);
        $this->assertEquals(1024.0, $data['file_size_mb']); // 1GB = 1024MB
        $this->assertEquals(3600, $data['duration_seconds']);
        $this->assertEquals(7, $data['total_segments']); // 5 + 1 + 1
        $this->assertEquals('not_started', $data['sermon_processing_status']);
        $this->assertEquals(null, $data['sermon_id']);
        $this->assertArrayHasKey('segment_summary', $data);
        $this->assertArrayHasKey('processing_duration_seconds', $data);
    }

    public function test_generate_processing_report_throws_exception_for_missing_processing()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing record not found for ID: nonexistent-id');

        $this->logger->generateProcessingReport('nonexistent-id');
    }

    public function test_log_processing_completion_success()
    {
        $processingId = 'test-processing-id';
        $summary = ['segments' => 5, 'sermon_created' => true];

        $this->logger->logProcessingCompletion($processingId, true, $summary);

        Log::shouldHaveReceived('log')
            ->once()
            ->with(
                'info',
                'Livestream processing completed successfully',
                \Mockery::on(function ($data) use ($processingId, $summary) {
                    return $data['processing_id'] === $processingId
                        && $data['success'] === true
                        && $data['summary'] === $summary
                        && isset($data['timestamp']);
                })
            );
    }

    public function test_log_processing_completion_failure()
    {
        $processingId = 'test-processing-id';
        $summary = ['error' => 'Video corruption detected'];

        $this->logger->logProcessingCompletion($processingId, false, $summary);

        Log::shouldHaveReceived('log')
            ->once()
            ->with(
                'error',
                'Livestream processing failed',
                \Mockery::on(function ($data) use ($processingId, $summary) {
                    return $data['processing_id'] === $processingId
                        && $data['success'] === false
                        && $data['summary'] === $summary
                        && isset($data['timestamp']);
                })
            );
    }

    public function test_get_recent_processing_activity()
    {
        // Create test data - must be livestream type since getRecentProcessingActivity filters by livestream
        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'completed',
            'created_at' => now()->subHours(2),
            'completed_at' => now()->subHours(1),
        ]);

        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'failed',
            'created_at' => now()->subHours(1),
        ]);

        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'processing',
            'created_at' => now()->subMinutes(30),
        ]);

        // Create old data that should be excluded
        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'completed',
            'created_at' => now()->subDays(2),
        ]);

        $activity = $this->logger->getRecentProcessingActivity(24);

        $this->assertEquals(3, $activity['total_processed']);
        $this->assertEquals(1, $activity['successful']);
        $this->assertEquals(1, $activity['failed']);
        $this->assertEquals(1, $activity['in_progress']);
        $this->assertEquals(60.0, $activity['average_duration_minutes']); // 1 hour for the completed one
    }

    public function test_processing_report_methods()
    {
        $data = [
            'processing_id' => 'test-id',
            'status' => 'completed',
            'total_segments' => 5,
            'processing_duration_seconds' => 300,
            'errors' => ['Error 1'],
            'warnings' => ['Warning 1', 'Warning 2'],
        ];

        $report = new ProcessingReport($data);

        $this->assertEquals($data, $report->toArray());
        $this->assertEquals('completed', $report->getStatus());
        $this->assertEquals(5, $report->getSegmentCount());
        $this->assertEquals(300, $report->getProcessingDuration());
        $this->assertTrue($report->hasErrors());
        $this->assertTrue($report->hasWarnings());

        $json = $report->toJson();
        $this->assertJson($json);
        $this->assertEquals($data, json_decode($json, true));
    }
}
