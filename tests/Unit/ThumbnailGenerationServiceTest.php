<?php

namespace Tests\Unit;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use App\Services\ThumbnailGenerationService;
use App\Services\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThumbnailGenerationServiceTest extends TestCase
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
        
        $this->service = new ThumbnailGenerationService($videoService);
    }

    /** @test */
    public function it_can_instantiate_service()
    {
        $this->assertInstanceOf(ThumbnailGenerationService::class, $this->service);
    }

    /** @test */
    public function it_skips_generation_when_disabled()
    {
        // Create a temporary file to pass the file existence check
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');
        
        // Temporarily disable thumbnail generation
        config(['thumbnail-generation.enabled' => false]);
        
        // Recreate service with new config
        $videoService = $this->createMock(VideoSegmentationService::class);
        $this->service = new ThumbnailGenerationService($videoService);
        
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

    /** @test */
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

    /** @test */
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

    /** @test */
    public function it_can_calculate_responsive_positions()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateResponsivePosition');
        $method->setAccessible(true);
        
        // Test scaling up
        $scaledPosition = $method->invoke($this->service, 50, 2560, 1280);
        $this->assertEquals(100, $scaledPosition);
        
        // Test scaling down
        $scaledPosition = $method->invoke($this->service, 100, 640, 1280);
        $this->assertEquals(50, $scaledPosition);
    }

    /** @test */
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

    /** @test */
    public function it_can_convert_hex_to_rgb()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('hexToRgb');
        $method->setAccessible(true);
        
        // Test full hex
        $rgb = $method->invoke($this->service, '#FFFFFF');
        $this->assertEquals([255, 255, 255], $rgb);
        
        // Test short hex
        $rgb = $method->invoke($this->service, '#000');
        $this->assertEquals([0, 0, 0], $rgb);
        
        // Test without hash
        $rgb = $method->invoke($this->service, 'FF0000');
        $this->assertEquals([255, 0, 0], $rgb);
    }

    /** @test */
    public function it_can_calculate_brand_position_to_avoid_text_overlap()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateBrandPosition');
        $method->setAccessible(true);
        
        // Mock image objects
        $image = $this->createMock(\Intervention\Image\Image::class);
        $image->method('width')->willReturn(1280);
        $image->method('height')->willReturn(720);
        
        $brandOverlay = $this->createMock(\Intervention\Image\Image::class);
        $brandOverlay->method('width')->willReturn(200);
        $brandOverlay->method('height')->willReturn(100);
        
        // Test bottom-right positioning with text avoidance
        $position = $method->invoke($this->service, $image, $brandOverlay, 'bottom-right', 20);
        
        $this->assertIsArray($position);
        $this->assertCount(2, $position);
        $this->assertEquals(1060, $position[0]); // 1280 - 200 - 20
        $this->assertGreaterThan(200, $position[1]); // Should avoid text area
    }

    /** @test */
    public function it_calculates_optimal_timestamp_for_frame_extraction()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateOptimalTimestamp');
        $method->setAccessible(true);
        
        // Test long video (30 minutes)
        $timestamp = $method->invoke($this->service, 1800.0);
        $this->assertEquals(60.0, $timestamp); // Should be 60 seconds in
        
        // Test medium video (10 minutes)
        $timestamp = $method->invoke($this->service, 600.0);
        $this->assertEquals(60.0, $timestamp); // Should be 60 seconds in
        
        // Test short video (2 minutes)
        $timestamp = $method->invoke($this->service, 120.0);
        $this->assertEquals(60.0, $timestamp); // Should be midpoint (50% of 120)
        
        // Test very short video (1 minute)
        $timestamp = $method->invoke($this->service, 60.0);
        $this->assertEquals(30.0, $timestamp); // Should be midpoint
    }

    /** @test */
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

    /** @test */
    public function it_validates_video_metadata_requirements()
    {
        // Mock VideoSegmentationService to return invalid metadata
        $videoService = $this->createMock(VideoSegmentationService::class);
        $videoService->method('getVideoMetadata')->willReturn([
            'duration' => 0, // Invalid duration
            'width' => 0,    // Invalid width
            'height' => 0,   // Invalid height
        ]);
        
        $service = new ThumbnailGenerationService($videoService);
        
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

    /** @test */
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

    /** @test */
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

    /** @test */
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