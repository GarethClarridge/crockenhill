<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\StorageAdapterHelper;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FrameExtractionServiceTest extends TestCase
{
    private FrameExtractionService $service;

    private VideoSegmentationService|Mockery\MockInterface $videoService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Config::set('thumbnail-generation', [
            'processing' => [
                'temp_disk' => 'local',
                'temp_path' => 'temp/thumbnails',
                'cleanup_temp_files' => true,
            ],
            'ffmpeg' => [
                'path' => '/usr/bin/ffmpeg',
                'probe_path' => '/usr/bin/ffprobe',
                'timeout' => 300,
                'threads' => 2,
            ],
            'extraction' => [
                'start_offset' => 300,
                'end_buffer' => 60,
                'fallback_position' => 0.5,
            ],
        ]);

        $this->videoService = Mockery::mock(VideoSegmentationService::class);
        $this->service = new FrameExtractionService($this->videoService, app(StorageAdapterHelper::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- Instantiation ----

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(FrameExtractionService::class, $this->service);
    }

    // ---- calculateOptimalTimestamp ----

    #[Test]
    public function it_returns_start_offset_for_long_videos(): void
    {
        // Duration 3600s, start_offset=300, end_buffer=60
        // maxTimestamp = 3600 - 60 = 3540, target = 300
        // min(300, 3540) = 300
        $result = $this->service->calculateOptimalTimestamp(3600.0);
        $this->assertEquals(300.0, $result);
    }

    #[Test]
    public function it_uses_fallback_position_for_very_short_videos(): void
    {
        // Duration 100s, start_offset(300) + end_buffer(60) = 360 > 100
        // Falls back to duration * fallback_position = 100 * 0.5 = 50
        $result = $this->service->calculateOptimalTimestamp(100.0);
        $this->assertEquals(50.0, $result);
    }

    #[Test]
    public function it_caps_at_max_timestamp_for_medium_videos(): void
    {
        // Duration 350s, start_offset(300) + end_buffer(60) = 360 > 350
        // Falls back to 350 * 0.5 = 175
        $result = $this->service->calculateOptimalTimestamp(350.0);
        $this->assertEquals(175.0, $result);
    }

    #[Test]
    public function it_handles_zero_duration(): void
    {
        $result = $this->service->calculateOptimalTimestamp(0.0);
        $this->assertEquals(0.0, $result);
    }

    #[Test]
    public function it_handles_duration_exactly_at_boundary(): void
    {
        // Duration 360s, start_offset(300) + end_buffer(60) = 360 == 360
        // Falls back to 360 * 0.5 = 180
        $result = $this->service->calculateOptimalTimestamp(360.0);
        $this->assertEquals(180.0, $result);
    }

    #[Test]
    public function it_returns_start_offset_when_less_than_max(): void
    {
        // Duration 400s, start_offset=300, end_buffer=60
        // maxTimestamp = 400 - 60 = 340, target = 300
        // min(300, 340) = 300
        $result = $this->service->calculateOptimalTimestamp(400.0);
        $this->assertEquals(300.0, $result);
    }

    #[Test]
    public function it_calculates_five_evenly_spaced_candidate_timestamps_for_long_videos(): void
    {
        $result = $this->service->calculateCandidateTimestamps(1800.0, 5);

        $this->assertCount(5, $result);
        $this->assertEquals([300.0, 660.0, 1020.0, 1380.0, 1740.0], $result);
    }

    #[Test]
    public function it_falls_back_to_proportional_candidate_timestamps_for_short_videos(): void
    {
        $result = $this->service->calculateCandidateTimestamps(200.0, 5);

        $this->assertCount(5, $result);
        $this->assertEquals([40.0, 70.0, 100.0, 130.0, 160.0], $result);
    }

    #[Test]
    public function it_deduplicates_candidate_timestamps_when_the_window_is_tiny(): void
    {
        $result = $this->service->calculateCandidateTimestamps(0.4, 5);

        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertLessThanOrEqual(5, count($result));
        $this->assertEquals(array_values(array_unique($result)), $result);
    }

    // ---- getVideoMetadata ----

    #[Test]
    public function it_delegates_to_video_segmentation_service(): void
    {
        $expectedMetadata = [
            'duration' => 3600.0,
            'width' => 1920,
            'height' => 1080,
            'format_name' => 'mp4',
            'size' => 1000000,
            'bit_rate' => 5000000,
            'codec' => 'h264',
        ];

        $this->videoService->shouldReceive('getVideoMetadata')
            ->once()
            ->with('/path/to/video.mp4')
            ->andReturn($expectedMetadata);

        $result = $this->service->getVideoMetadata('/path/to/video.mp4');

        $this->assertEquals($expectedMetadata, $result);
    }

    #[Test]
    public function it_returns_defaults_when_metadata_extraction_fails(): void
    {
        $this->videoService->shouldReceive('getVideoMetadata')
            ->once()
            ->andThrow(new \Exception('FFprobe failed'));

        $result = $this->service->getVideoMetadata('/path/to/video.mp4');

        $this->assertEquals(0.0, $result['duration']);
        $this->assertEquals(1920, $result['width']);
        $this->assertEquals(1080, $result['height']);
        $this->assertEquals('unknown', $result['format_name']);
        $this->assertEquals(0, $result['size']);
        $this->assertEquals(0, $result['bit_rate']);
        $this->assertEquals('unknown', $result['codec']);
    }

    // ---- videoFileExists ----

    #[Test]
    public function it_checks_file_existence_on_disk_when_disk_provided(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('video.mp4', 'content');

        $this->assertTrue($this->service->videoFileExists('video.mp4', 'public'));
        $this->assertFalse($this->service->videoFileExists('nonexistent.mp4', 'public'));
    }

    #[Test]
    public function it_checks_local_file_existence_when_no_disk(): void
    {
        $this->assertFalse($this->service->videoFileExists('/nonexistent/video.mp4'));
    }

    // ---- extractBaseFrame (requires FFmpeg, test null return on failure) ----

    #[Test]
    public function it_returns_null_when_extraction_fails(): void
    {
        $result = $this->service->extractBaseFrame('/nonexistent/video.mp4', 10.0);

        $this->assertNull($result);
    }

    // ---- cleanupDownloadedVideo ----

    #[Test]
    public function it_does_nothing_when_path_is_null(): void
    {
        $this->expectNotToPerformAssertions();

        $this->service->cleanupDownloadedVideo(null);
    }

    #[Test]
    public function it_deletes_temp_video_file_when_cleanup_enabled(): void
    {
        $tempFile = storage_path('app/temp/test_video_cleanup.mp4');
        $tempDir = dirname($tempFile);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        file_put_contents($tempFile, 'test video content');

        Config::set('thumbnail-generation.processing.cleanup_temp_files', true);

        $this->service->cleanupDownloadedVideo($tempFile);

        $this->assertFileDoesNotExist($tempFile);
    }

    #[Test]
    public function it_does_not_delete_when_cleanup_disabled(): void
    {
        $tempFile = storage_path('app/temp/test_video_no_cleanup.mp4');
        $tempDir = dirname($tempFile);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        file_put_contents($tempFile, 'test video content');

        Config::set('thumbnail-generation.processing.cleanup_temp_files', false);

        $this->service->cleanupDownloadedVideo($tempFile);

        $this->assertFileExists($tempFile);

        // Cleanup after test
        unlink($tempFile);
    }

    // ---- ensureLocalVideoPath ----

    #[Test]
    public function it_returns_path_directly_when_no_disk(): void
    {
        $result = $this->service->ensureLocalVideoPath('/direct/path/video.mp4');

        $this->assertEquals('/direct/path/video.mp4', $result);
    }

    #[Test]
    public function it_returns_disk_path_for_local_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('videos/test.mp4', 'content');

        $result = $this->service->ensureLocalVideoPath('videos/test.mp4', 'public');

        // Should return the full local path from the disk
        $this->assertStringContainsString('videos/test.mp4', $result);
    }
}
