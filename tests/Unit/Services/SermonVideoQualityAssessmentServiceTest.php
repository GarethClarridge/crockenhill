<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonVideoQualityStatus;
use App\Models\Sermon;
use App\Services\FrameExtractionService;
use App\Services\SermonVideoQualityAssessmentService;
use App\Services\StorageAdapterHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonVideoQualityAssessmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'thumbnail-generation.processing.temp_disk' => 'local',
            'thumbnail-generation.processing.cleanup_temp_files' => true,
            'media-processing.video_quality.sampling.coarse_sample_count' => 3,
            'media-processing.video_quality.sampling.burst_window_count' => 1,
            'media-processing.video_quality.sampling.burst_frames_per_window' => 5,
            'media-processing.video_quality.thresholds.low_detail_score' => 0.04,
        ]);
    }

    #[Test]
    public function obviously_blank_frames_are_rejected(): void
    {
        $result = $this->assessWithFrames($this->frames('black', 8));

        $this->assertSame(SermonVideoQualityStatus::Rejected, $result->status);
        $this->assertSame('mostly_black', $result->reason);
        $this->assertGreaterThanOrEqual(0.75, $result->blankFrameRatio);
    }

    #[Test]
    public function frozen_burst_windows_are_rejected(): void
    {
        $coarseFrames = $this->frames('checker', 3);
        $burstFrames = array_map(
            fn (int $index): string => $this->frame("frozen-{$index}.png", 'checker', 99),
            range(1, 5),
        );

        $result = $this->assessWithFrames([...$coarseFrames, ...$burstFrames]);

        $this->assertSame(SermonVideoQualityStatus::Rejected, $result->status);
        $this->assertSame('frozen_frames', $result->reason);
        $this->assertSame(1.0, $result->frozenPairRatio);
    }

    #[Test]
    public function varied_healthy_frames_are_approved(): void
    {
        $result = $this->assessWithFrames($this->frames('checker', 8));

        $this->assertSame(SermonVideoQualityStatus::Approved, $result->status);
        $this->assertNull($result->reason);
        $this->assertSame(0.0, $result->blankFrameRatio);
    }

    #[Test]
    public function service_errors_return_safe_analysis_failed_result(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $frameExtractionService = $this->createMock(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willThrowException(new \RuntimeException('boom'));

        $storageHelper = $this->createMock(StorageAdapterHelper::class);
        $service = new SermonVideoQualityAssessmentService($frameExtractionService, $storageHelper);

        $result = $service->assess($sermon, 'sermons/video.mp4', 'public');

        $this->assertSame(SermonVideoQualityStatus::NeedsReview, $result->status);
        $this->assertSame('analysis_failed', $result->reason);
    }

    /**
     * @param  list<string>  $frames
     */
    private function assessWithFrames(array $frames): \App\Data\SermonVideoQualityAssessmentResult
    {
        Storage::disk('public')->put('sermons/video.mp4', 'video');

        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $frameExtractionService = $this->createMock(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willReturn(true);
        $frameExtractionService->method('ensureLocalVideoPath')->willReturn('local-video.mp4');
        $frameExtractionService->method('getVideoMetadata')->willReturn(['duration' => 120.0]);
        $frameExtractionService->method('extractBaseFrame')->willReturnOnConsecutiveCalls(...$frames);
        $frameExtractionService->method('cleanupDownloadedVideo');

        $storageHelper = $this->createMock(StorageAdapterHelper::class);
        $storageHelper->method('isS3CompatibleDisk')->willReturn(false);

        $service = new SermonVideoQualityAssessmentService($frameExtractionService, $storageHelper);

        return $service->assess($sermon, 'sermons/video.mp4', 'public');
    }

    /**
     * @return list<string>
     */
    private function frames(string $style, int $count, int $seed = 1): array
    {
        $frames = [];

        for ($index = 0; $index < $count; $index++) {
            $frames[] = $this->frame("{$style}-{$seed}-{$index}.png", $style, $seed + $index);
        }

        return $frames;
    }

    private function frame(string $filename, string $style, int $seed): string
    {
        $path = 'temp/video-quality/'.$filename;
        Storage::disk('local')->makeDirectory('temp/video-quality');
        $fullPath = Storage::disk('local')->path($path);

        $image = imagecreatetruecolor(64, 64);

        if ($style === 'black') {
            imagefilledrectangle($image, 0, 0, 63, 63, imagecolorallocate($image, 0, 0, 0));
        } else {
            for ($y = 0; $y < 64; $y++) {
                for ($x = 0; $x < 64; $x++) {
                    $light = ((int) floor($x / 4) + (int) floor($y / 4) + $seed) % 2 === 0;
                    $value = $light ? 235 : 25;
                    imagesetpixel($image, $x, $y, imagecolorallocate($image, $value, $value, $value));
                }
            }
        }

        imagepng($image, $fullPath);
        imagedestroy($image);

        return $path;
    }
}
