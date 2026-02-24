<?php

namespace Tests\Performance;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\LivestreamProcessingLogger;
use App\Services\LivestreamProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LivestreamProcessingPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('sermon_disk');
        Queue::fake();

        Config::set('livestream-processing', [
            'rms_threshold' => -30.0,
            'min_section_duration' => 60.0,
            'min_sermon_duration' => 300.0,
            'max_file_size' => 5368709120, // 5GB for performance testing
            'supported_formats' => ['mp4', 'mov', 'avi', 'mkv'],
            'sermon_disk' => 'sermon_disk',
        ]);
    }

    public function test_large_file_processing_performance()
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Create a large mock video file (simulate 2 hour recording)
        $largeVideoFile = UploadedFile::fake()->create('large_livestream.mp4', 500000, 'video/mp4'); // 500MB

        $service = app(LivestreamProcessingService::class);

        // Measure processing initiation time
        $initStartTime = microtime(true);
        $result = $service->processLivestream($largeVideoFile);
        $initEndTime = microtime(true);

        $initDuration = $initEndTime - $initStartTime;

        // Verify processing record was created efficiently
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $result->processingId,
            'status' => 'pending',
        ]);

        $processing = MediaProcessingLog::where('processing_id', $result->processingId)->first();
        $this->assertNotNull($processing);

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $totalDuration = $endTime - $startTime;
        $memoryUsage = $endMemory - $startMemory;

        // Performance assertions
        $this->assertLessThan(5.0, $initDuration, 'Processing initiation should take less than 5 seconds');
        $this->assertLessThan(10.0, $totalDuration, 'Total setup should take less than 10 seconds');
        $this->assertLessThan(100 * 1024 * 1024, $memoryUsage, 'Memory usage should be less than 100MB for setup');

        // Log performance metrics
        echo "\nLarge File Processing Performance:\n";
        echo '- Initialization time: '.round($initDuration, 3)." seconds\n";
        echo '- Total setup time: '.round($totalDuration, 3)." seconds\n";
        echo '- Memory usage: '.round($memoryUsage / 1024 / 1024, 2)." MB\n";
    }

    public function test_concurrent_processing_performance()
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $service = app(LivestreamProcessingService::class);
        $processingIds = [];

        // Simulate 5 concurrent uploads
        for ($i = 1; $i <= 5; $i++) {
            $videoFile = UploadedFile::fake()->create("concurrent_{$i}.mp4", 50000, 'video/mp4');

            $concurrentStartTime = microtime(true);
            $result = $service->processLivestream($videoFile);
            $concurrentEndTime = microtime(true);

            $processingIds[] = $result->processingId;

            $concurrentDuration = $concurrentEndTime - $concurrentStartTime;
            $this->assertLessThan(2.0, $concurrentDuration, "Concurrent upload {$i} should take less than 2 seconds");
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $totalDuration = $endTime - $startTime;
        $memoryUsage = $endMemory - $startMemory;

        // Verify all processing records were created
        foreach ($processingIds as $processingId) {
            $this->assertDatabaseHas('media_processing_logs', [
                'processing_id' => $processingId,
                'status' => 'pending',
            ]);
        }

        // Performance assertions for concurrent processing
        $this->assertLessThan(15.0, $totalDuration, '5 concurrent uploads should complete in less than 15 seconds');
        $this->assertLessThan(200 * 1024 * 1024, $memoryUsage, 'Memory usage should be less than 200MB for 5 concurrent uploads');

        echo "\nConcurrent Processing Performance:\n";
        echo '- Total time for 5 concurrent uploads: '.round($totalDuration, 3)." seconds\n";
        echo '- Average time per upload: '.round($totalDuration / 5, 3)." seconds\n";
        echo '- Memory usage: '.round($memoryUsage / 1024 / 1024, 2)." MB\n";
    }

    public function test_segmentation_performance_with_many_segments()
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Create a processing record
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'perf-test-segmentation',
            'status' => 'processing',
            'duration_seconds' => 7200, // 2 hours
        ]);

        // Create many segments (simulate complex segmentation)
        $segmentCount = 50;
        $segments = [];

        $segmentCreationStart = microtime(true);
        for ($i = 0; $i < $segmentCount; $i++) {
            $startTime = $i * 144; // Every ~2.4 minutes
            $endTime = $startTime + 120; // 2 minute segments

            $segment = LivestreamSegment::create([
                'media_processing_log_id' => $processing->id,
                'segment_index' => $i + 1,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => 120,
                'classification' => $i % 3 === 0 ? 'song' : 'speech',
                'is_sermon_segment' => $i === 25, // One sermon segment in the middle
            ]);

            $segments[] = $segment;
        }
        $segmentCreationEnd = microtime(true);

        // Test querying performance with many segments
        $queryStart = microtime(true);
        $retrievedSegments = LivestreamSegment::where('media_processing_log_id', $processing->id)
            ->orderBy('start_time')
            ->get();
        $queryEnd = microtime(true);

        // Test sermon segment identification performance
        $sermonIdentificationStart = microtime(true);
        $sermonSegment = $retrievedSegments->where('is_sermon_segment', true)->first();
        $speechSegments = $retrievedSegments->where('classification', 'speech');
        $songSegments = $retrievedSegments->where('classification', 'song');
        $sermonIdentificationEnd = microtime(true);

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $totalDuration = $endTime - $startTime;
        $memoryUsage = $endMemory - $startMemory;
        $segmentCreationDuration = $segmentCreationEnd - $segmentCreationStart;
        $queryDuration = $queryEnd - $queryStart;
        $sermonIdentificationDuration = $sermonIdentificationEnd - $sermonIdentificationStart;

        // Verify correctness
        $this->assertEquals($segmentCount, $retrievedSegments->count());
        $this->assertNotNull($sermonSegment);
        $this->assertGreaterThan(0, $speechSegments->count());
        $this->assertGreaterThan(0, $songSegments->count());

        // Performance assertions
        $this->assertLessThan(5.0, $segmentCreationDuration, 'Creating 50 segments should take less than 5 seconds');
        $this->assertLessThan(0.1, $queryDuration, 'Querying 50 segments should take less than 0.1 seconds');
        $this->assertLessThan(0.05, $sermonIdentificationDuration, 'Sermon identification should take less than 0.05 seconds');
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsage, 'Memory usage should be less than 50MB');

        echo "\nSegmentation Performance with {$segmentCount} segments:\n";
        echo '- Segment creation time: '.round($segmentCreationDuration, 3)." seconds\n";
        echo '- Query time: '.round($queryDuration, 3)." seconds\n";
        echo '- Sermon identification time: '.round($sermonIdentificationDuration, 3)." seconds\n";
        echo '- Total memory usage: '.round($memoryUsage / 1024 / 1024, 2)." MB\n";
    }

    public function test_logging_performance_under_load()
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $logger = app(LivestreamProcessingLogger::class);

        // Test logging performance with many log entries
        $logCount = 1000;
        $processingId = 'perf-test-logging';

        $loggingStart = microtime(true);
        for ($i = 0; $i < $logCount; $i++) {
            $step = ['video_analysis', 'segmentation', 'extraction', 'processing'][rand(0, 3)];
            $context = [
                'iteration' => $i,
                'file_size' => rand(1000000, 100000000),
                'memory_usage' => memory_get_usage(true),
            ];

            if ($i % 10 === 0) {
                // Occasional error logging
                $exception = new \Exception("Test error {$i}");
                $logger->logError($processingId, $step, $exception);
            } elseif ($i % 5 === 0) {
                // Occasional warning logging
                $logger->logWarning($processingId, $step, "Test warning {$i}", $context);
            } else {
                // Regular step logging
                $logger->logProcessingStep($processingId, $step, $context);
            }
        }
        $loggingEnd = microtime(true);

        // Test performance metrics logging
        $metricsStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $executionTime = rand(100, 5000) / 1000; // 0.1 to 5 seconds
            $metrics = [
                'segments_found' => rand(1, 10),
                'file_processed' => true,
                'quality_score' => rand(70, 100),
            ];

            $logger->logPerformanceMetrics($processingId, 'performance_test', $executionTime, $metrics);
        }
        $metricsEnd = microtime(true);

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $totalDuration = $endTime - $startTime;
        $memoryUsage = $endMemory - $startMemory;
        $loggingDuration = $loggingEnd - $loggingStart;
        $metricsDuration = $metricsEnd - $metricsStart;

        // Performance assertions
        $this->assertLessThan(10.0, $loggingDuration, "{$logCount} log entries should take less than 10 seconds");
        $this->assertLessThan(2.0, $metricsDuration, '100 performance metrics should take less than 2 seconds');
        $this->assertLessThan(100 * 1024 * 1024, $memoryUsage, 'Memory usage should be less than 100MB');

        echo "\nLogging Performance:\n";
        echo "- {$logCount} log entries time: ".round($loggingDuration, 3)." seconds\n";
        echo '- Average time per log entry: '.round($loggingDuration / $logCount * 1000, 3)." ms\n";
        echo '- 100 performance metrics time: '.round($metricsDuration, 3)." seconds\n";
        echo '- Total memory usage: '.round($memoryUsage / 1024 / 1024, 2)." MB\n";
    }
}
