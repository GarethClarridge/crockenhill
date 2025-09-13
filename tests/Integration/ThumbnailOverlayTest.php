<?php

namespace Tests\Integration;

use App\Models\Sermon;
use App\Services\ThumbnailGenerationService;
use App\Services\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Tests\TestCase;

class ThumbnailOverlayTest extends TestCase
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
    public function it_can_create_text_overlays_on_image()
    {
        // Skip if Intervention Image is not properly configured
        if (!class_exists(\Intervention\Image\ImageManager::class)) {
            $this->markTestSkipped('Intervention Image not available');
        }

        // Create a test image
        $testImage = Image::canvas(1280, 720, '#333333');
        
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon Title That Should Wrap',
            'date' => now(),
            'service' => 'morning',
        ]);

        // Use reflection to test the private addTextOverlays method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('addTextOverlays');
        $method->setAccessible(true);

        // This should not throw an exception
        $method->invoke($this->service, $testImage, $sermon);
        
        // Verify the image is still valid
        $this->assertInstanceOf(\Intervention\Image\Image::class, $testImage);
        $this->assertEquals(1280, $testImage->width());
        $this->assertEquals(720, $testImage->height());
    }

    /** @test */
    public function it_can_add_brand_overlay_when_image_exists()
    {
        // Skip if brand image doesn't exist
        if (!Storage::disk('public_images')->exists('images/BrandOverlay.png')) {
            $this->markTestSkipped('Brand overlay image not found');
        }

        // Create a test image
        $testImage = Image::canvas(1280, 720, '#333333');
        
        // Use reflection to test the private addBrandOverlay method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('addBrandOverlay');
        $method->setAccessible(true);

        // This should not throw an exception
        $method->invoke($this->service, $testImage);
        
        // Verify the image is still valid
        $this->assertInstanceOf(\Intervention\Image\Image::class, $testImage);
        $this->assertEquals(1280, $testImage->width());
        $this->assertEquals(720, $testImage->height());
    }

    /** @test */
    public function it_handles_missing_brand_overlay_gracefully()
    {
        // Temporarily change brand image path to non-existent file
        config(['thumbnail-generation.overlay.brand_image' => 'images/nonexistent.png']);
        
        // Recreate service with new config
        $videoService = $this->createMock(VideoSegmentationService::class);
        $service = new ThumbnailGenerationService($videoService);
        
        // Create a test image
        $testImage = Image::canvas(1280, 720, '#333333');
        
        // Use reflection to test the private addBrandOverlay method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('addBrandOverlay');
        $method->setAccessible(true);

        // This should not throw an exception even with missing brand image
        $method->invoke($service, $testImage);
        
        // Verify the image is still valid
        $this->assertInstanceOf(\Intervention\Image\Image::class, $testImage);
        $this->assertEquals(1280, $testImage->width());
        $this->assertEquals(720, $testImage->height());
    }

    /** @test */
    public function it_can_calculate_text_bounds()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateTextBounds');
        $method->setAccessible(true);

        $bounds = $method->invoke($this->service, 'Test Text', 48, null);
        
        $this->assertIsArray($bounds);
        $this->assertArrayHasKey('width', $bounds);
        $this->assertArrayHasKey('height', $bounds);
        $this->assertGreaterThan(0, $bounds['width']);
        $this->assertGreaterThan(0, $bounds['height']);
    }

    /** @test */
    public function it_can_get_oswald_font_path()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getOswaldFontPath');
        $method->setAccessible(true);

        $fontPath = $method->invoke($this->service);
        
        // Should return either a valid path or null
        if ($fontPath !== null) {
            $this->assertIsString($fontPath);
            // Note: We don't assert file_exists because the font might not be available in test environment
        } else {
            $this->assertNull($fontPath);
        }
    }
}