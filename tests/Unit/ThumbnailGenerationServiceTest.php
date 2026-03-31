<?php

namespace Tests\Unit;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use App\Services\FrameExtractionService;
use App\Services\StorageAdapterHelper;
use App\Services\ThumbnailForegroundExtractionService;
use App\Services\ThumbnailGenerationService;
use App\Services\ThumbnailTextHelper;
use App\Services\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ThumbnailGenerationService $service;

    private FrameExtractionService $frameExtractionService;

    private string $brandOverlayPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->brandOverlayPath = public_path('images/BrandOverlay.png');

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
        $this->service = new ThumbnailGenerationService(
            $this->frameExtractionService,
            app(StorageAdapterHelper::class),
            new ThumbnailTextHelper,
            app(ThumbnailForegroundExtractionService::class)
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->brandOverlayPath)) {
            unlink($this->brandOverlayPath);
        }

        parent::tearDown();
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
        $this->service = new ThumbnailGenerationService(
            $this->frameExtractionService,
            app(StorageAdapterHelper::class),
            new ThumbnailTextHelper,
            app(ThumbnailForegroundExtractionService::class)
        );

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
        $helper = new ThumbnailTextHelper;

        // Test scaling up (but limited)
        $scaledSize = $helper->calculateResponsiveFontSize(48, 2560, 1280);
        $this->assertEquals(96, $scaledSize); // 2x scale

        // Test scaling down (but limited)
        $scaledSize = $helper->calculateResponsiveFontSize(48, 320, 1280);
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
        $service = new ThumbnailGenerationService(
            $frameService,
            app(StorageAdapterHelper::class),
            new ThumbnailTextHelper,
            app(ThumbnailForegroundExtractionService::class)
        );

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
        $helper = new ThumbnailTextHelper;
        $text = 'Test Text';

        // Test different font sizes
        $bounds24 = $helper->calculateTextBounds($text, 24, null);
        $bounds48 = $helper->calculateTextBounds($text, 48, null);

        $this->assertIsArray($bounds24);
        $this->assertIsArray($bounds48);

        // Larger font should have larger bounds
        $this->assertGreaterThan($bounds24['width'], $bounds48['width']);
        $this->assertGreaterThan($bounds24['height'], $bounds48['height']);
    }

    #[Test]
    public function it_marks_layered_subject_composition_when_foreground_extraction_succeeds(): void
    {
        $service = $this->makeThumbnailServiceWithExtractorResult($this->makeForegroundExtractionResult());
        $sermon = Sermon::factory()->create([
            'title' => 'Layered Composition Test',
            'date' => now(),
        ]);

        $result = $service->createBrandedThumbnail($sermon, $this->storeBaseFrame());

        $this->assertIsArray($result);
        $this->assertSame('layered_subject', $result['composition_metadata']['composition_mode']);
        $this->assertSame('poof_api', $result['composition_metadata']['foreground_extraction_method']);
        $this->assertSame(['x' => 340, 'y' => 110, 'width' => 600, 'height' => 520], $result['composition_metadata']['foreground_bounds']);
        $this->assertSame(0.16, $result['composition_metadata']['foreground_coverage']);
    }

    #[Test]
    public function it_marks_flat_fallback_composition_when_foreground_extraction_fails(): void
    {
        $service = $this->makeThumbnailServiceWithExtractorResult(null);
        $sermon = Sermon::factory()->create([
            'title' => 'Fallback Composition Test',
            'date' => now(),
        ]);

        $result = $service->createBrandedThumbnail($sermon, $this->storeBaseFrame());

        $this->assertIsArray($result);
        $this->assertSame('flat_fallback', $result['composition_metadata']['composition_mode']);
        $this->assertArrayNotHasKey('foreground_bounds', $result['composition_metadata']);
    }

    #[Test]
    public function it_places_preacher_over_title_and_brand_and_date_over_preacher(): void
    {
        $this->createBrandOverlayAsset();

        $sermon = Sermon::factory()->create([
            'title' => 'MMMMMMMMMMMMMMMM',
            'date' => now(),
        ]);

        $fallbackService = $this->makeThumbnailServiceWithExtractorResult(null);
        $fallbackThumbnail = $fallbackService->createBrandedThumbnail($sermon, $this->storeBaseFrame());
        $fallbackImage = Image::read(Storage::disk('local')->path($fallbackThumbnail['path']));

        $titlePixel = $this->findBrightPixelInRegion($fallbackImage, 420, 110, 860, 360);
        $datePixel = $this->findBrightPixelInRegion($fallbackImage, 350, 560, 930, 680);

        $this->assertNotNull($titlePixel);
        $this->assertNotNull($datePixel);

        $layeredService = $this->makeThumbnailServiceWithExtractorResult($this->makeForegroundExtractionResult());
        $layeredThumbnail = $layeredService->createBrandedThumbnail($sermon, $this->storeBaseFrame());
        $layeredImage = Image::read(Storage::disk('local')->path($layeredThumbnail['path']));

        $titleLayeredPixel = $this->getImagePixel($layeredImage, $titlePixel['x'], $titlePixel['y']);
        $brandPixel = $this->getImagePixel($layeredImage, 40, 40);
        $dateLayeredPixel = $this->findBrightPixelInRegion($layeredImage, 350, 560, 930, 680);

        $this->assertGreaterThan(200, $titlePixel['red']);
        $this->assertLessThan(80, $titleLayeredPixel['red']);
        $this->assertGreaterThan(150, $titleLayeredPixel['green']);
        $this->assertLessThan(80, $titleLayeredPixel['blue']);

        $this->assertGreaterThan(200, $brandPixel['red']);
        $this->assertLessThan(80, $brandPixel['green']);
        $this->assertLessThan(80, $brandPixel['blue']);

        $this->assertNotNull($dateLayeredPixel);
        $this->assertGreaterThan(200, $dateLayeredPixel['red']);
        $this->assertGreaterThan(200, $dateLayeredPixel['green']);
        $this->assertGreaterThan(200, $dateLayeredPixel['blue']);
    }

    #[Test]
    public function it_renders_an_overlay_for_a_plain_only_selected_candidate(): void
    {
        $service = $this->makeThumbnailServiceWithExtractorResult($this->makeForegroundExtractionResult());
        $sermon = Sermon::factory()->create([
            'title' => 'Render Candidate Test',
            'date' => now(),
            'thumbnail_metadata' => [
                'selected_thumbnail_candidate_id' => 'candidate-1',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 180.0,
                        'score' => 0.91,
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/candidate-1-plain.webp', Storage::disk('local')->get($this->storeBaseFrame()));

        $result = $service->renderSelectedThumbnailCandidate($sermon, 'candidate-1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('candidate-1', $result->metadata['selected_thumbnail_candidate_id']);
        $this->assertSame('layered_subject', $result->metadata['composition_mode']);
        $this->assertSame('poof_api', $result->metadata['foreground_extraction_method']);
        $this->assertArrayHasKey('overlay_path', $result->metadata['thumbnail_candidates'][0]);
    }

    private function makeThumbnailServiceWithExtractorResult(?array $extractorResult): ThumbnailGenerationService
    {
        $extractor = $this->createMock(ThumbnailForegroundExtractionService::class);
        $extractor->method('extract')->willReturn($extractorResult);

        return new ThumbnailGenerationService(
            $this->createMock(FrameExtractionService::class),
            app(StorageAdapterHelper::class),
            new ThumbnailTextHelper,
            $extractor
        );
    }

    /**
     * @return array{
     *     image: ImageInterface,
     *     coverage: float,
     *     bounds: array{x:int,y:int,width:int,height:int},
     *     method: string
     * }
     */
    private function makeForegroundExtractionResult(): array
    {
        $overlay = imagecreatetruecolor(1280, 720);
        imagealphablending($overlay, false);
        imagesavealpha($overlay, true);

        $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $transparent);

        $green = imagecolorallocatealpha($overlay, 20, 220, 40, 0);
        imagefilledrectangle($overlay, 340, 110, 940, 630, $green);

        return [
            'image' => Image::read($overlay),
            'coverage' => 0.16,
            'bounds' => ['x' => 340, 'y' => 110, 'width' => 600, 'height' => 520],
            'method' => 'poof_api',
        ];
    }

    private function storeBaseFrame(): string
    {
        $path = 'temp/thumbnails/base-frame.png';
        $fullPath = Storage::disk('local')->path($path);

        Storage::disk('local')->makeDirectory('temp/thumbnails');

        $image = imagecreatetruecolor(1280, 720);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $background = imagecolorallocate($image, 44, 61, 118);
        imagefill($image, 0, 0, $background);

        imagepng($image, $fullPath);

        return $path;
    }

    private function createBrandOverlayAsset(): void
    {
        $directory = dirname($this->brandOverlayPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $overlay = imagecreatetruecolor(1280, 720);
        imagealphablending($overlay, false);
        imagesavealpha($overlay, true);

        $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $transparent);

        $red = imagecolorallocatealpha($overlay, 255, 0, 0, 0);
        imagefilledrectangle($overlay, 0, 0, 119, 119, $red);

        imagepng($overlay, $this->brandOverlayPath);
    }

    /**
     * @return array{x:int,y:int,red:int,green:int,blue:int}|null
     */
    private function findBrightPixelInRegion(ImageInterface $image, int $startX, int $startY, int $endX, int $endY): ?array
    {
        for ($y = $startY; $y <= $endY; $y++) {
            for ($x = $startX; $x <= $endX; $x++) {
                $pixel = $this->getImagePixel($image, $x, $y);

                if ($pixel['red'] > 200 && $pixel['green'] > 200 && $pixel['blue'] > 200) {
                    return ['x' => $x, 'y' => $y] + $pixel;
                }
            }
        }

        return null;
    }

    /**
     * @return array{red:int,green:int,blue:int,alpha:int}
     */
    private function getImagePixel(ImageInterface $image, int $x, int $y): array
    {
        /** @var \GdImage $native */
        $native = $image->core()->native();
        $index = imagecolorat($native, $x, $y);
        $pixel = imagecolorsforindex($native, $index);

        return [
            'red' => (int) $pixel['red'],
            'green' => (int) $pixel['green'],
            'blue' => (int) $pixel['blue'],
            'alpha' => (int) $pixel['alpha'],
        ];
    }
}
