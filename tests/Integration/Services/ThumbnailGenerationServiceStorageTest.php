<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use App\Services\Media\Thumbnail\ThumbnailCanvasComposer;
use App\Services\Media\Thumbnail\ThumbnailForegroundExtractionService;
use App\Services\Media\Thumbnail\ThumbnailGenerationService;
use App\Services\Media\Thumbnail\ThumbnailTextHelper;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonExposurePolicy;
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

        $videoService = $this->createStub(VideoSegmentationService::class);
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
        $this->service = new ThumbnailGenerationService(
            $this->frameExtractionService,
            app(StorageAdapterHelper::class),
            app(ThumbnailForegroundExtractionService::class),
            app(ThumbnailCanvasComposer::class),
            app(SermonExposurePolicy::class),
        );
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

    #[Test]
    public function it_deletes_existing_candidate_thumbnails_before_regeneration(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Existing Thumbnail Sermon',
            'date' => now(),
            'video_file_path' => 'videos/existing.mp4',
            'thumbnail_file_path' => 'sermons/thumbnails/current-overlay.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/current-plain.webp',
                'card_thumbnail_path' => 'sermons/thumbnails/current-card.webp',
                'selected_thumbnail_candidate_id' => 'candidate-2',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.81,
                        'card_path' => 'sermons/thumbnails/candidate-1-card.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                    [
                        'id' => 'candidate-2',
                        'timestamp' => 240.0,
                        'score' => 0.92,
                        'card_path' => 'sermons/thumbnails/current-card.webp',
                        'overlay_path' => 'sermons/thumbnails/current-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/current-plain.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/current-overlay.webp', 'overlay');
        Storage::disk('public')->put('sermons/thumbnails/current-plain.webp', 'plain');
        Storage::disk('public')->put('sermons/thumbnails/current-card.webp', 'card');
        Storage::disk('public')->put('sermons/thumbnails/candidate-1-card.webp', 'candidate card');
        Storage::disk('public')->put('sermons/thumbnails/candidate-1-plain.webp', 'candidate plain');

        $service = $this->getMockBuilder(ThumbnailGenerationService::class)
            ->setConstructorArgs([
                $this->frameExtractionService,
                app(StorageAdapterHelper::class),
                app(ThumbnailForegroundExtractionService::class),
                app(ThumbnailCanvasComposer::class),
                app(SermonExposurePolicy::class),
            ])
            ->onlyMethods(['generateThumbnail'])
            ->getMock();

        $service->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($sermon)), 'videos/existing.mp4', 'public')
            ->willReturn(ThumbnailResult::success('sermons/thumbnails/new-overlay.webp'));

        $result = $service->regenerateThumbnail($sermon);

        $this->assertTrue($result->isSuccess());
        Storage::disk('public')->assertMissing('sermons/thumbnails/current-overlay.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/current-plain.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/current-card.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/candidate-1-card.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/candidate-1-plain.webp');
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

    // ---- fallbackTextWrap tests (on ThumbnailTextHelper) ----

    #[Test]
    public function it_wraps_text_by_character_estimation(): void
    {
        $helper = new ThumbnailTextHelper;

        $longText = 'This is a very long sermon title that should definitely be wrapped across multiple lines';
        $result = $helper->fallbackTextWrap($longText, 200, 24);

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
        $helper = new ThumbnailTextHelper;

        $result = $helper->fallbackTextWrap('Hello', 400, 24);
        $this->assertEquals('Hello', $result);
    }

    #[Test]
    public function it_handles_empty_text_in_fallback_wrap(): void
    {
        $helper = new ThumbnailTextHelper;

        $result = $helper->fallbackTextWrap('', 400, 24);
        $this->assertEquals('', $result);
    }

    // ---- calculateTextBounds tests (on ThumbnailTextHelper) ----

    #[Test]
    public function it_estimates_text_bounds_without_gd_font(): void
    {
        $helper = new ThumbnailTextHelper;

        // Pass null fontPath to force fallback estimation
        $bounds = $helper->calculateTextBounds('Test Text', 24, null);

        $this->assertArrayHasKey('width', $bounds);
        $this->assertArrayHasKey('height', $bounds);
        $this->assertGreaterThan(0, $bounds['width']);
        $this->assertGreaterThan(0, $bounds['height']);
    }

    #[Test]
    public function it_scales_estimated_bounds_with_font_size(): void
    {
        $helper = new ThumbnailTextHelper;

        $bounds24 = $helper->calculateTextBounds('Test Text', 24, null);
        $bounds48 = $helper->calculateTextBounds('Test Text', 48, null);

        // Larger font should produce larger bounds
        $this->assertGreaterThan($bounds24['width'], $bounds48['width']);
        $this->assertGreaterThan($bounds24['height'], $bounds48['height']);
    }

    #[Test]
    public function it_handles_multiline_text_bounds(): void
    {
        $helper = new ThumbnailTextHelper;

        $singleLine = $helper->calculateTextBounds('One line', 24, null);
        $multiLine = $helper->calculateTextBounds("Line one\nLine two\nLine three", 24, null);

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

    // ---- calculateResponsiveFontSize tests (on ThumbnailTextHelper) ----

    #[Test]
    public function it_scales_font_size_proportionally(): void
    {
        $helper = new ThumbnailTextHelper;

        // Same width as reference should return base size
        $this->assertEquals(48, $helper->calculateResponsiveFontSize(48, 1280, 1280));

        // Double width should return double size (capped at 2x)
        $this->assertEquals(96, $helper->calculateResponsiveFontSize(48, 2560, 1280));

        // Half width should return half size (capped at 0.5x)
        $this->assertEquals(24, $helper->calculateResponsiveFontSize(48, 640, 1280));
    }

    #[Test]
    public function it_clamps_font_scaling_to_bounds(): void
    {
        $helper = new ThumbnailTextHelper;

        // Very large width should cap at 2x
        $this->assertEquals(96, $helper->calculateResponsiveFontSize(48, 5000, 1280));

        // Very small width should cap at 0.5x
        $this->assertEquals(24, $helper->calculateResponsiveFontSize(48, 100, 1280));
    }
}
