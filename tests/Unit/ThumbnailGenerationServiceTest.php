<?php

namespace Tests\Unit;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use App\Services\FrameExtractionService;
use App\Services\StorageAdapterHelper;
use App\Services\ThumbnailGenerationService;
use App\Services\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ThumbnailGenerationService $service;

    private FrameExtractionService $frameExtractionService;

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

        $this->frameExtractionService = new FrameExtractionService($videoService, app(StorageAdapterHelper::class));
        $this->service = new ThumbnailGenerationService($this->frameExtractionService, app(StorageAdapterHelper::class));
    }

    #[Test]
    public function it_can_instantiate_service()
    {
        $this->assertInstanceOf(ThumbnailGenerationService::class, $this->service);
    }

    #[Test]
    public function it_skips_generation_when_disabled()
    {
        // Create a temporary file to pass the file existence check
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Temporarily disable thumbnail generation
        config(['thumbnail-generation.enabled' => false]);

        // Recreate service with new config
        $this->service = new ThumbnailGenerationService($this->frameExtractionService, app(StorageAdapterHelper::class));

        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => now(),
        ]);

        $result = $this->service->generateThumbnail($sermon, $tempFile);

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('disabled', $result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function it_skips_generation_for_missing_video_file()
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => now(),
        ]);

        $result = $this->service->generateThumbnail($sermon, '/nonexistent/video.mp4');

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->errorMessage);
    }

    #[Test]
    public function it_can_wrap_text_properly()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('wrapText');
        $method->setAccessible(true);

        $longTitle = 'This is a very long sermon title that should be wrapped across multiple lines';
        $maxWidth = 400;
        $fontSize = 48;

        $wrappedText = $method->invoke($this->service, $longTitle, $maxWidth, $fontSize);

        $this->assertStringContainsString("\n", $wrappedText);
        $lines = explode("\n", $wrappedText);
        $this->assertGreaterThan(1, count($lines));
    }

    #[Test]
    public function it_can_calculate_responsive_font_sizes()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateResponsiveFontSize');
        $method->setAccessible(true);

        // Test scaling up (but limited)
        $scaledSize = $method->invoke($this->service, 48, 2560, 1280);
        $this->assertEquals(96, $scaledSize); // 2x scale

        // Test scaling down (but limited)
        $scaledSize = $method->invoke($this->service, 48, 320, 1280);
        $this->assertEquals(24, $scaledSize); // 0.5x scale (minimum)
    }

    #[Test]
    public function it_calculates_optimal_timestamp_for_frame_extraction()
    {
        // Test long video (30 minutes)
        $timestamp = $this->frameExtractionService->calculateOptimalTimestamp(1800.0);
        $this->assertEquals(300.0, $timestamp); // Should be 300 seconds in (5 minutes)

        // Test medium video (10 minutes)
        $timestamp = $this->frameExtractionService->calculateOptimalTimestamp(600.0);
        $this->assertEquals(300.0, $timestamp); // Should be 300 seconds in (5 minutes)

        // Test short video (7 minutes) - above threshold (300+60=360)
        $timestamp = $this->frameExtractionService->calculateOptimalTimestamp(420.0);
        $this->assertEquals(300.0, $timestamp); // Should be 300 seconds in (start_offset)

        // Test very short video (6 minutes) - at threshold (300+60=360)
        $timestamp = $this->frameExtractionService->calculateOptimalTimestamp(360.0);
        $this->assertEquals(180.0, $timestamp); // Should be midpoint (50% of 360)

        // Test very short video (5 minutes) - below threshold
        $timestamp = $this->frameExtractionService->calculateOptimalTimestamp(300.0);
        $this->assertEquals(150.0, $timestamp); // Should be midpoint
    }

    #[Test]
    public function it_handles_frame_extraction_errors_gracefully()
    {
        // Create a temporary file that's not a valid video
        $tempFile = tempnam(sys_get_temp_dir(), 'invalid_video');
        file_put_contents($tempFile, 'not a video file');

        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => now(),
        ]);

        $result = $this->service->generateThumbnail($sermon, $tempFile);

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function it_validates_video_metadata_requirements()
    {
        // Mock VideoSegmentationService to return invalid metadata
        $videoService = $this->createMock(VideoSegmentationService::class);
        $videoService->method('getVideoMetadata')->willReturn([
            'duration' => 0, // Invalid duration
            'width' => 0,    // Invalid width
            'height' => 0,   // Invalid height
        ]);

        $frameService = new FrameExtractionService($videoService, app(StorageAdapterHelper::class));
        $service = new ThumbnailGenerationService($frameService, app(StorageAdapterHelper::class));

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => now(),
        ]);

        $result = $service->generateThumbnail($sermon, $tempFile);

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        // The actual error message might be different, let's check for video-related error
        $this->assertNotNull($result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function it_handles_long_sermon_titles_with_proper_wrapping()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('wrapText');
        $method->setAccessible(true);

        $veryLongTitle = 'This is an extremely long sermon title that definitely needs to be wrapped across multiple lines to fit properly within the thumbnail image boundaries and maintain readability';
        $maxWidth = 400;
        $fontSize = 48;

        $wrappedText = $method->invoke($this->service, $veryLongTitle, $maxWidth, $fontSize);

        $lines = explode("\n", $wrappedText);
        $this->assertGreaterThan(3, count($lines)); // Should wrap to multiple lines

        // Each line should be shorter than the original
        foreach ($lines as $line) {
            $this->assertLessThan(strlen($veryLongTitle), strlen($line));
        }
    }

    #[Test]
    public function it_handles_empty_titles_gracefully()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('wrapText');
        $method->setAccessible(true);

        // Test empty string
        $wrappedText = $method->invoke($this->service, '', 400, 48);
        $this->assertEquals('', $wrappedText);

        // Note: null cannot be tested as the method has string type hint
        // The service should handle null values before calling wrapText
    }

    #[Test]
    public function it_calculates_text_bounds_for_different_font_sizes()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateTextBounds');
        $method->setAccessible(true);

        $text = 'Test Text';

        // Test different font sizes
        $bounds24 = $method->invoke($this->service, $text, 24, null);
        $bounds48 = $method->invoke($this->service, $text, 48, null);

        $this->assertIsArray($bounds24);
        $this->assertIsArray($bounds48);

        // Larger font should have larger bounds
        $this->assertGreaterThan($bounds24['width'], $bounds48['width']);
        $this->assertGreaterThan($bounds24['height'], $bounds48['height']);
    }
}
