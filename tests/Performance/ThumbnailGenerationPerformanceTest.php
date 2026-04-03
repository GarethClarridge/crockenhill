<?php

namespace Tests\Performance;

use App\Models\Sermon;
use App\Services\FrameExtractionService;
use App\Services\StorageAdapterHelper;
use App\Services\ThumbnailCanvasComposer;
use App\Services\ThumbnailForegroundExtractionService;
use App\Services\ThumbnailGenerationService;
use App\Services\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailGenerationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private ThumbnailGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the VideoSegmentationService dependency
        $videoService = $this->createMock(VideoSegmentationService::class);
        $videoService->method('getVideoMetadata')->willReturn([
            'duration' => 1800.0, // 30 minutes
            'width' => 1920,
            'height' => 1080,
            'format_name' => 'mp4',
            'size' => 1000000,
            'bit_rate' => 5000000,
            'codec' => 'h264',
        ]);

        $frameExtractionService = new FrameExtractionService($videoService, app(StorageAdapterHelper::class));
        $this->service = new ThumbnailGenerationService(
            $frameExtractionService,
            app(StorageAdapterHelper::class),
            app(ThumbnailForegroundExtractionService::class),
            app(ThumbnailCanvasComposer::class)
        );
    }

    #[Test]
    public function thumbnail_generation_completes_within_reasonable_time()
    {
        // Skip if not in performance testing environment
        if (! env('RUN_PERFORMANCE_TESTS', false)) {
            $this->markTestSkipped('Performance tests are disabled');
        }

        $sermon = Sermon::factory()->create([
            'title' => 'Performance Test Sermon with a Very Long Title That Should Be Wrapped Properly',
            'date' => now(),
            'service' => 'morning',
        ]);

        // Create a temporary file to simulate video
        $tempFile = tempnam(sys_get_temp_dir(), 'perf_test_video');
        file_put_contents($tempFile, str_repeat('fake video content', 1000));

        $startTime = microtime(true);

        // This should complete within a reasonable time even if it fails
        $result = $this->service->generateThumbnail($sermon, $tempFile);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Should complete within 5 seconds (even for failures)
        $this->assertLessThan(5.0, $executionTime,
            "Thumbnail generation took {$executionTime} seconds, which exceeds the 5-second limit");

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function text_wrapping_performance_with_long_titles()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('wrapText');
        $method->setAccessible(true);

        // Create an extremely long title
        $longTitle = str_repeat('This is a very long sermon title that needs wrapping. ', 50);

        $startTime = microtime(true);

        // Perform text wrapping
        $wrappedText = $method->invoke($this->service, $longTitle, 400, 48);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Text wrapping should be very fast (under 100ms)
        $this->assertLessThan(0.1, $executionTime,
            "Text wrapping took {$executionTime} seconds, which is too slow");

        // Verify the text was actually wrapped
        $this->assertStringContainsString("\n", $wrappedText);
    }

    #[Test]
    public function responsive_calculations_performance()
    {
        $reflection = new \ReflectionClass($this->service);
        $positionMethod = $reflection->getMethod('calculateResponsivePosition');
        $fontMethod = $reflection->getMethod('calculateResponsiveFontSize');
        $positionMethod->setAccessible(true);
        $fontMethod->setAccessible(true);

        $iterations = 1000;

        $startTime = microtime(true);

        // Perform many responsive calculations
        for ($i = 0; $i < $iterations; $i++) {
            $positionMethod->invoke($this->service, 50, 1920, 1280);
            $fontMethod->invoke($this->service, 48, 1920, 1280);
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        $avgTime = $executionTime / $iterations;

        // Each calculation should be very fast (under 1ms on average)
        $this->assertLessThan(0.001, $avgTime,
            "Average responsive calculation took {$avgTime} seconds, which is too slow");
    }

    #[Test]
    public function color_conversion_performance()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('hexToRgb');
        $method->setAccessible(true);

        $colors = ['#FFFFFF', '#000000', '#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', '#00FFFF'];
        $iterations = 1000;

        $startTime = microtime(true);

        // Perform many color conversions
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($colors as $color) {
                $method->invoke($this->service, $color);
            }
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        $avgTime = $executionTime / ($iterations * count($colors));

        // Each color conversion should be very fast
        $this->assertLessThan(0.0001, $avgTime,
            "Average color conversion took {$avgTime} seconds, which is too slow");
    }

    #[Test]
    public function memory_usage_stays_reasonable_during_processing()
    {
        // Skip if not in performance testing environment
        if (! env('RUN_PERFORMANCE_TESTS', false)) {
            $this->markTestSkipped('Performance tests are disabled');
        }

        $sermon = Sermon::factory()->create([
            'title' => 'Memory Test Sermon',
            'date' => now(),
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'memory_test_video');
        file_put_contents($tempFile, str_repeat('fake video content', 10000));

        $initialMemory = memory_get_usage(true);

        // Process thumbnail (may fail, but shouldn't consume excessive memory)
        $result = $this->service->generateThumbnail($sermon, $tempFile);

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (under 50MB)
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease,
            'Memory usage increased by '.number_format($memoryIncrease / 1024 / 1024, 2).'MB, which is excessive');

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function concurrent_thumbnail_generation_performance()
    {
        // Skip if not in performance testing environment
        if (! env('RUN_PERFORMANCE_TESTS', false)) {
            $this->markTestSkipped('Performance tests are disabled');
        }

        $sermons = [];
        $tempFiles = [];

        // Create multiple sermons and temp files
        for ($i = 1; $i <= 5; $i++) {
            $sermons[] = Sermon::factory()->create([
                'title' => "Concurrent Test Sermon {$i}",
                'date' => now(),
            ]);

            $tempFile = tempnam(sys_get_temp_dir(), "concurrent_test_{$i}");
            file_put_contents($tempFile, "fake video content {$i}");
            $tempFiles[] = $tempFile;
        }

        $startTime = microtime(true);

        // Process all thumbnails sequentially (simulating concurrent processing)
        foreach ($sermons as $index => $sermon) {
            $this->service->generateThumbnail($sermon, $tempFiles[$index]);
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        $avgTime = $executionTime / count($sermons);

        // Average processing time should be reasonable
        $this->assertLessThan(2.0, $avgTime,
            "Average thumbnail processing took {$avgTime} seconds, which is too slow for concurrent processing");

        // Cleanup
        foreach ($tempFiles as $tempFile) {
            unlink($tempFile);
        }
    }

    #[Test]
    public function brand_position_calculation_performance()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateBrandPosition');
        $method->setAccessible(true);

        // Mock image objects
        $image = $this->createMock(\Intervention\Image\Image::class);
        $image->method('width')->willReturn(1920);
        $image->method('height')->willReturn(1080);

        $brandOverlay = $this->createMock(\Intervention\Image\Image::class);
        $brandOverlay->method('width')->willReturn(200);
        $brandOverlay->method('height')->willReturn(100);

        $positions = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];
        $iterations = 1000;

        $startTime = microtime(true);

        // Perform many brand position calculations
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($positions as $position) {
                $method->invoke($this->service, $image, $brandOverlay, $position, 20);
            }
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        $avgTime = $executionTime / ($iterations * count($positions));

        // Each calculation should be very fast
        $this->assertLessThan(0.0001, $avgTime,
            "Average brand position calculation took {$avgTime} seconds, which is too slow");
    }
}
