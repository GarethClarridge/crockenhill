<?php

declare(strict_types=1);

namespace Tests\Performance;

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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('performance')]
class ThumbnailGenerationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private ThumbnailGenerationService $service;

    private ThumbnailTextHelper $textHelper;

    protected function setUp(): void
    {
        parent::setUp();

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

        $frameExtractionService = new FrameExtractionService($videoService, app(StorageAdapterHelper::class));

        $this->service = new ThumbnailGenerationService(
            $frameExtractionService,
            app(StorageAdapterHelper::class),
            app(ThumbnailForegroundExtractionService::class),
            app(ThumbnailCanvasComposer::class),
            app(SermonExposurePolicy::class),
        );

        $this->textHelper = app(ThumbnailTextHelper::class);
    }

    #[Test]
    public function thumbnail_generation_completes_within_reasonable_time(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Performance Test Sermon with a Very Long Title That Should Be Wrapped Properly',
            'date' => now(),
            'service' => 'morning',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'perf_test_video');
        file_put_contents($tempFile, str_repeat('fake video content', 1000));

        $startTime = microtime(true);
        $this->service->generateThumbnail($sermon, $tempFile);
        $executionTime = microtime(true) - $startTime;

        unlink($tempFile);

        $this->assertLessThan(5.0, $executionTime,
            "Thumbnail generation took {$executionTime}s — exceeds the 5-second limit");
    }

    #[Test]
    public function text_wrapping_performance_with_long_titles(): void
    {
        $longTitle = str_repeat('This is a very long sermon title that needs wrapping. ', 50);

        $startTime = microtime(true);
        $wrappedText = $this->textHelper->wrapText($longTitle, 400, 48);
        $executionTime = microtime(true) - $startTime;

        $this->assertLessThan(0.1, $executionTime,
            "Text wrapping took {$executionTime}s — should be under 100ms");

        $this->assertStringContainsString("\n", $wrappedText);
    }

    #[Test]
    public function responsive_font_size_calculation_performance(): void
    {
        $iterations = 1000;

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->textHelper->calculateResponsiveFontSize(48, 1920, 1280);
        }

        $avgTime = (microtime(true) - $startTime) / $iterations;

        $this->assertLessThan(0.001, $avgTime,
            "Average responsive font-size calculation took {$avgTime}s — should be under 1ms");
    }

    #[Test]
    public function color_conversion_performance(): void
    {
        $colors = ['#FFFFFF', '#000000', '#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', '#00FFFF'];
        $iterations = 1000;

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            foreach ($colors as $color) {
                $this->textHelper->hexToRgb($color);
            }
        }

        $avgTime = (microtime(true) - $startTime) / ($iterations * count($colors));

        $this->assertLessThan(0.0001, $avgTime,
            "Average color conversion took {$avgTime}s — should be under 0.1ms");
    }

    #[Test]
    public function memory_usage_stays_reasonable_during_processing(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Memory Test Sermon',
            'date' => now(),
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'memory_test_video');
        file_put_contents($tempFile, str_repeat('fake video content', 10000));

        $initialMemory = memory_get_usage(true);
        $this->service->generateThumbnail($sermon, $tempFile);
        $memoryIncrease = memory_get_usage(true) - $initialMemory;

        unlink($tempFile);

        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease,
            'Memory usage increased by '.number_format($memoryIncrease / 1024 / 1024, 2).'MB — exceeds 50MB limit');
    }
}
