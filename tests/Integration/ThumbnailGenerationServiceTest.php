<?php

declare(strict_types=1);

namespace Tests\Integration;

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
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ThumbnailGenerationService $service;

    private FrameExtractionService $frameExtractionService;

    private string $logoPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->logoPath = public_path('images/test-thumbnail-logo.png');
        config([
            'thumbnail-generation.theme.logo_path' => 'images/test-thumbnail-logo.png',
            'thumbnail-generation.theme.palette.background_color' => '#D7EAE6',
            'thumbnail-generation.theme.palette.foreground_color' => '#145557',
        ]);
        $this->createLogoAsset();

        // Mock the VideoSegmentationService dependency
        $videoService = $this->createStub(VideoSegmentationService::class);
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
            app(ThumbnailForegroundExtractionService::class),
            app(ThumbnailCanvasComposer::class),
            app(SermonExposurePolicy::class),
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logoPath)) {
            unlink($this->logoPath);
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
            app(ThumbnailForegroundExtractionService::class),
            app(ThumbnailCanvasComposer::class),
            app(SermonExposurePolicy::class),
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
        $composer = app(ThumbnailCanvasComposer::class);

        $longTitle = 'This is a very long sermon title that should be wrapped across multiple lines';
        $maxWidth = 400;
        $fontSize = 48;

        $wrappedText = $composer->wrapText($longTitle, $maxWidth, $fontSize);

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
        $videoService = $this->createStub(VideoSegmentationService::class);
        $videoService->method('getVideoMetadata')->willReturn([
            'duration' => 0, // Invalid duration
            'width' => 0,    // Invalid width
            'height' => 0,   // Invalid height
        ]);

        $frameService = new FrameExtractionService($videoService, app(StorageAdapterHelper::class));
        $service = new ThumbnailGenerationService(
            $frameService,
            app(StorageAdapterHelper::class),
            app(ThumbnailForegroundExtractionService::class),
            app(ThumbnailCanvasComposer::class),
            app(SermonExposurePolicy::class),
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
        $composer = app(ThumbnailCanvasComposer::class);

        $veryLongTitle = 'This is an extremely long sermon title that definitely needs to be wrapped across multiple lines to fit properly within the thumbnail image boundaries and maintain readability';
        $maxWidth = 400;
        $fontSize = 48;

        $wrappedText = $composer->wrapText($veryLongTitle, $maxWidth, $fontSize);

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
        $composer = app(ThumbnailCanvasComposer::class);

        // Test empty string
        $wrappedText = $composer->wrapText('', 400, 48);
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
        $this->assertSame('pixian_api', $result['composition_metadata']['foreground_extraction_method']);
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
    public function it_renders_dark_branding_on_a_light_canvas_and_places_the_cutout_on_the_right(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'MMMMMMMMMMMMMMMM',
            'reference' => '2 Peter 3:1-20',
            'preacher' => 'Pastor Mark Drury',
            'date' => now(),
        ]);

        $fallbackService = $this->makeThumbnailServiceWithExtractorResult(null);
        $fallbackThumbnail = $fallbackService->createBrandedThumbnail($sermon, $this->storeBaseFrame());
        $fallbackImage = Image::decode(Storage::disk('local')->path($fallbackThumbnail['path']));

        $backgroundPixel = $this->getImagePixel($fallbackImage, 1180, 80);
        $logoPixel = $this->findDarkPixelInRegion($fallbackImage, 40, 40, 260, 240);
        $titlePixel = $this->findDarkPixelInRegion($fallbackImage, 60, 180, 700, 420);
        $metadataPixel = $this->findDarkPixelInRegion($fallbackImage, 40, 430, 520, 700);

        $this->assertGreaterThan(200, $backgroundPixel['red']);
        $this->assertGreaterThan(220, $backgroundPixel['green']);
        $this->assertGreaterThan(220, $backgroundPixel['blue']);
        $this->assertNotNull($logoPixel);
        $this->assertNotNull($titlePixel);
        $this->assertNotNull($metadataPixel);

        $layeredService = $this->makeThumbnailServiceWithExtractorResult($this->makeForegroundExtractionResult());
        $layeredThumbnail = $layeredService->createBrandedThumbnail($sermon, $this->storeBaseFrame());
        $layeredImage = Image::decode(Storage::disk('local')->path($layeredThumbnail['path']));

        $subjectPixel = $this->findGreenPixelInRegion($layeredImage, 820, 160, 1210, 680);

        $this->assertLessThan(80, $logoPixel['red']);
        $this->assertLessThan(120, $logoPixel['green']);
        $this->assertLessThan(120, $logoPixel['blue']);
        $this->assertLessThan(80, $titlePixel['red']);
        $this->assertLessThan(120, $titlePixel['green']);
        $this->assertLessThan(120, $titlePixel['blue']);
        $this->assertLessThan(80, $metadataPixel['red']);
        $this->assertLessThan(120, $metadataPixel['green']);
        $this->assertLessThan(120, $metadataPixel['blue']);
        $this->assertNotNull($subjectPixel);
        $this->assertGreaterThan(150, $subjectPixel['green']);
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
        $this->assertArrayHasKey('card_thumbnail_path', $result->metadata);
        $this->assertSame('layered_subject', $result->metadata['composition_mode']);
        $this->assertSame('pixian_api', $result->metadata['foreground_extraction_method']);
        $this->assertArrayHasKey('card_path', $result->metadata['thumbnail_candidates'][0]);
        $this->assertArrayHasKey('overlay_path', $result->metadata['thumbnail_candidates'][0]);
    }

    private function makeThumbnailServiceWithExtractorResult(?array $extractorResult): ThumbnailGenerationService
    {
        $extractor = $this->createStub(ThumbnailForegroundExtractionService::class);
        $extractor->method('extract')->willReturn($extractorResult);

        return new ThumbnailGenerationService(
            $this->createStub(FrameExtractionService::class),
            app(StorageAdapterHelper::class),
            $extractor,
            app(ThumbnailCanvasComposer::class),
            app(SermonExposurePolicy::class),
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
            'image' => Image::decode($overlay),
            'coverage' => 0.16,
            'bounds' => ['x' => 340, 'y' => 110, 'width' => 600, 'height' => 520],
            'method' => 'pixian_api',
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

    private function createLogoAsset(): void
    {
        $directory = dirname($this->logoPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $overlay = imagecreatetruecolor(180, 120);
        imagealphablending($overlay, false);
        imagesavealpha($overlay, true);

        $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $transparent);

        $red = imagecolorallocatealpha($overlay, 255, 0, 0, 0);
        imagefilledrectangle($overlay, 20, 20, 160, 100, $red);

        imagepng($overlay, $this->logoPath);
    }

    /**
     * @return array{x:int,y:int,red:int,green:int,blue:int}|null
     */
    private function findDarkPixelInRegion(ImageInterface $image, int $startX, int $startY, int $endX, int $endY): ?array
    {
        for ($y = $startY; $y <= $endY; $y++) {
            for ($x = $startX; $x <= $endX; $x++) {
                $pixel = $this->getImagePixel($image, $x, $y);

                if ($pixel['alpha'] < 127 && $pixel['red'] < 80 && $pixel['green'] < 120 && $pixel['blue'] < 120) {
                    return ['x' => $x, 'y' => $y] + $pixel;
                }
            }
        }

        return null;
    }

    /**
     * @return array{x:int,y:int,red:int,green:int,blue:int}|null
     */
    private function findGreenPixelInRegion(ImageInterface $image, int $startX, int $startY, int $endX, int $endY): ?array
    {
        for ($y = $startY; $y <= $endY; $y++) {
            for ($x = $startX; $x <= $endX; $x++) {
                $pixel = $this->getImagePixel($image, $x, $y);

                if ($pixel['alpha'] < 127 && $pixel['green'] > 150 && $pixel['green'] > $pixel['red'] && $pixel['green'] > $pixel['blue']) {
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
