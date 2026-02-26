<?php

namespace Tests\Unit\Services;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use App\Services\FrameExtractionService;
use App\Services\StorageAdapterHelper;
use App\Services\ThumbnailGenerationService;
use App\Services\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailGenerationServiceStorageTest extends TestCase
{
    use RefreshDatabase;

    private ThumbnailGenerationService $service;

    private FrameExtractionService $frameExtractionService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $videoService = $this->createMock(VideoSegmentationService::class);
        $videoService->method('getVideoMetadata')->willReturn([
            'duration' => 1800.0,
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

    // ---- storeThumbnail tests ----

    #[Test]
    public function it_stores_thumbnail_to_final_location(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Test Sermon', 'date' => now()]);

        // Create a temp file on the temp disk
        $tempContent = 'fake thumbnail content';
        $tempPath = 'thumbnails/temp/test_thumb.webp';
        Storage::disk('local')->put($tempPath, $tempContent);

        $result = $this->service->storeThumbnail($tempPath, $sermon);

        $this->assertNotNull($result);
        $this->assertStringContainsString('sermon_'.$sermon->id, $result);
        $this->assertStringEndsWith('.webp', $result);
    }

    #[Test]
    public function it_returns_null_when_temp_file_missing(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Test Sermon', 'date' => now()]);

        $result = $this->service->storeThumbnail('nonexistent/path.webp', $sermon);

        $this->assertNull($result);
    }

    #[Test]
    public function it_stores_plain_thumbnail_variant_with_plain_suffix(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Test Sermon', 'date' => now()]);

        $tempContent = 'fake plain thumbnail content';
        $tempPath = 'thumbnails/temp/test_plain.webp';
        Storage::disk('local')->put($tempPath, $tempContent);

        $result = $this->service->storeThumbnail($tempPath, $sermon, 'plain');

        $this->assertNotNull($result);
        $this->assertStringContainsString('sermon_'.$sermon->id, $result);
        $this->assertStringContainsString('_plain.webp', $result);
    }

    // ---- regenerateThumbnail tests ----

    #[Test]
    public function it_skips_regeneration_when_sermon_has_no_video(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => now(),
            'video_file_path' => null,
        ]);

        $result = $this->service->regenerateThumbnail($sermon);

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('no video', $result->errorMessage);
    }

    // ---- videoFileExists tests (now on FrameExtractionService) ----

    #[Test]
    public function it_checks_file_existence_via_storage_disk(): void
    {
        Storage::disk('public')->put('test_video.mp4', 'content');

        $this->assertTrue($this->frameExtractionService->videoFileExists('test_video.mp4', 'public'));
        $this->assertFalse($this->frameExtractionService->videoFileExists('nonexistent.mp4', 'public'));
    }

    #[Test]
    public function it_checks_local_file_existence_without_disk(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'content');

        $this->assertTrue($this->frameExtractionService->videoFileExists($tempFile, null));
        $this->assertFalse($this->frameExtractionService->videoFileExists('/nonexistent/video.mp4', null));

        unlink($tempFile);
    }

    // ---- ensureLocalVideoPath tests (now on FrameExtractionService) ----

    #[Test]
    public function it_returns_path_unchanged_without_disk(): void
    {
        $path = '/absolute/path/to/video.mp4';
        $result = $this->frameExtractionService->ensureLocalVideoPath($path, null);

        $this->assertEquals($path, $result);
    }

    #[Test]
    public function it_resolves_local_disk_to_full_path(): void
    {
        Storage::disk('public')->put('videos/test.mp4', 'content');

        $result = $this->frameExtractionService->ensureLocalVideoPath('videos/test.mp4', 'public');

        // Should return a full filesystem path
        $this->assertStringContainsString('videos/test.mp4', $result);
    }

    // ---- fallbackTextWrap tests (remains on ThumbnailGenerationService) ----

    #[Test]
    public function it_wraps_text_by_character_estimation(): void
    {
        $method = $this->getPrivateMethod('fallbackTextWrap');

        $longText = 'This is a very long sermon title that should definitely be wrapped across multiple lines';
        $result = $method->invoke($this->service, $longText, 200, 24);

        $lines = explode("\n", $result);
        $this->assertGreaterThan(1, count($lines));

        // Verify all words are preserved
        $this->assertEquals(
            str_word_count($longText),
            str_word_count(str_replace("\n", ' ', $result))
        );
    }

    #[Test]
    public function it_handles_single_word_in_fallback_wrap(): void
    {
        $method = $this->getPrivateMethod('fallbackTextWrap');

        $result = $method->invoke($this->service, 'Hello', 400, 24);
        $this->assertEquals('Hello', $result);
    }

    #[Test]
    public function it_handles_empty_text_in_fallback_wrap(): void
    {
        $method = $this->getPrivateMethod('fallbackTextWrap');

        $result = $method->invoke($this->service, '', 400, 24);
        $this->assertEquals('', $result);
    }

    // ---- calculateTextBounds tests (remains on ThumbnailGenerationService) ----

    #[Test]
    public function it_estimates_text_bounds_without_gd_font(): void
    {
        $method = $this->getPrivateMethod('calculateTextBounds');

        // Pass null fontPath to force fallback estimation
        $bounds = $method->invoke($this->service, 'Test Text', 24, null);

        $this->assertArrayHasKey('width', $bounds);
        $this->assertArrayHasKey('height', $bounds);
        $this->assertGreaterThan(0, $bounds['width']);
        $this->assertGreaterThan(0, $bounds['height']);
    }

    #[Test]
    public function it_scales_estimated_bounds_with_font_size(): void
    {
        $method = $this->getPrivateMethod('calculateTextBounds');

        $bounds24 = $method->invoke($this->service, 'Test Text', 24, null);
        $bounds48 = $method->invoke($this->service, 'Test Text', 48, null);

        // Larger font should produce larger bounds
        $this->assertGreaterThan($bounds24['width'], $bounds48['width']);
        $this->assertGreaterThan($bounds24['height'], $bounds48['height']);
    }

    #[Test]
    public function it_handles_multiline_text_bounds(): void
    {
        $method = $this->getPrivateMethod('calculateTextBounds');

        $singleLine = $method->invoke($this->service, 'One line', 24, null);
        $multiLine = $method->invoke($this->service, "Line one\nLine two\nLine three", 24, null);

        // Multi-line should be taller
        $this->assertGreaterThan($singleLine['height'], $multiLine['height']);
    }

    // ---- calculateOptimalTimestamp tests (now on FrameExtractionService) ----

    #[Test]
    public function it_uses_midpoint_for_short_videos(): void
    {
        // Very short video below start_offset + end_buffer threshold
        $timestamp = $this->frameExtractionService->calculateOptimalTimestamp(120.0); // 2 minutes

        // Should be at fallback_position (50%) of duration
        $this->assertEqualsWithDelta(60.0, $timestamp, 1.0);
    }

    #[Test]
    public function it_uses_start_offset_for_long_videos(): void
    {
        $timestamp = $this->frameExtractionService->calculateOptimalTimestamp(3600.0); // 1 hour

        // Should be at start_offset (300s / 5 minutes)
        $this->assertEquals(300.0, $timestamp);
    }

    // ---- calculateResponsiveFontSize tests (remains on ThumbnailGenerationService) ----

    #[Test]
    public function it_scales_font_size_proportionally(): void
    {
        $method = $this->getPrivateMethod('calculateResponsiveFontSize');

        // Same width as reference should return base size
        $this->assertEquals(48, $method->invoke($this->service, 48, 1280, 1280));

        // Double width should return double size (capped at 2x)
        $this->assertEquals(96, $method->invoke($this->service, 48, 2560, 1280));

        // Half width should return half size (capped at 0.5x)
        $this->assertEquals(24, $method->invoke($this->service, 48, 640, 1280));
    }

    #[Test]
    public function it_clamps_font_scaling_to_bounds(): void
    {
        $method = $this->getPrivateMethod('calculateResponsiveFontSize');

        // Very large width should cap at 2x
        $this->assertEquals(96, $method->invoke($this->service, 48, 5000, 1280));

        // Very small width should cap at 0.5x
        $this->assertEquals(24, $method->invoke($this->service, 48, 100, 1280));
    }

    private function getPrivateMethod(string $methodName): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }
}
