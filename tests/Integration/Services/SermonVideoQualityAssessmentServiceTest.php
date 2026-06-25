<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Enums\SermonVideoQualityStatus;
use App\Models\Sermon;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\SermonVideoQualityAssessmentService;
use App\Services\Processing\StorageAdapterHelper;
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
    public function frozen_burst_windows_need_review_when_auto_reject_is_disabled(): void
    {
        config(['media-processing.video_quality.auto_reject_frozen_frames' => false]);

        $coarseFrames = $this->frames('checker', 3);
        $burstFrames = array_map(
            fn (int $index): string => $this->frame("frozen-review-{$index}.png", 'checker', 99),
            range(1, 5),
        );

        $result = $this->assessWithFrames([...$coarseFrames, ...$burstFrames]);

        $this->assertSame(SermonVideoQualityStatus::NeedsReview, $result->status);
        $this->assertSame('frozen_frames', $result->reason);
        $this->assertSame(1.0, $result->frozenPairRatio);
    }

    #[Test]
    public function frozen_detection_requires_each_burst_window_to_match(): void
    {
        config([
            'media-processing.video_quality.sampling.burst_window_count' => 2,
            'media-processing.video_quality.thresholds.frozen_pair_ratio_reject' => 0.5,
        ]);

        $coarseFrames = $this->frames('checker', 3);
        $frozenWindow = array_map(
            fn (int $index): string => $this->frame("mixed-frozen-{$index}.png", 'checker', 99),
            range(1, 5),
        );
        $healthyWindow = $this->frames('checker', 5, 200);

        $result = $this->assessWithFrames([...$coarseFrames, ...$frozenWindow, ...$healthyWindow]);

        $this->assertSame(SermonVideoQualityStatus::Approved, $result->status);
        $this->assertNull($result->reason);
        $this->assertSame(0.0, $result->frozenPairRatio);
    }

    #[Test]
    public function very_low_detail_frames_can_be_flagged_for_review(): void
    {
        config([
            'media-processing.video_quality.thresholds.blank_variance' => -1.0,
            'media-processing.video_quality.thresholds.low_detail_ratio_reject' => 1.1,
        ]);

        $coarseFrames = $this->frames('gray', 3);
        $burstFrames = $this->frames('checker', 5, 20);

        $result = $this->assessWithFrames([...$coarseFrames, ...$burstFrames]);

        $this->assertSame(SermonVideoQualityStatus::NeedsReview, $result->status);
        $this->assertSame('very_low_detail', $result->reason);
        $this->assertSame(1.0, $result->lowDetailRatio);
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

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willThrowException(new \RuntimeException('boom'));

        $storageHelper = $this->createStub(StorageAdapterHelper::class);
        $service = new SermonVideoQualityAssessmentService($frameExtractionService, $storageHelper);

        $result = $service->assess($sermon, 'sermons/video.mp4', 'public');

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $result->status);
        $this->assertSame('analysis_failed', $result->reason);
    }

    #[Test]
    public function assess_and_retain_local_path_returns_path_for_s3_disk(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);
        $frames = $this->frames('checker', 8);

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willReturn(true);
        $frameExtractionService->method('ensureLocalVideoPath')->willReturn('temp/downloaded.mp4');
        $frameExtractionService->method('getVideoMetadata')->willReturn(['duration' => 120.0]);
        $frameExtractionService->method('extractBaseFrame')->willReturnOnConsecutiveCalls(...$frames);

        $storageHelper = $this->createStub(StorageAdapterHelper::class);
        $storageHelper->method('isS3CompatibleDisk')->willReturn(true);

        $service = new SermonVideoQualityAssessmentService($frameExtractionService, $storageHelper);

        $outcome = $service->assessAndRetainLocalPath($sermon, 'sermons/video.mp4', 'do_spaces');

        $this->assertSame(SermonVideoQualityStatus::Approved, $outcome['result']->status);
        $this->assertSame('temp/downloaded.mp4', $outcome['localVideoPath']);
    }

    #[Test]
    public function assess_and_retain_local_path_returns_null_path_for_local_disk(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);
        $frames = $this->frames('checker', 8);

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willReturn(true);
        $frameExtractionService->method('ensureLocalVideoPath')->willReturn('storage/sermons/video.mp4');
        $frameExtractionService->method('getVideoMetadata')->willReturn(['duration' => 120.0]);
        $frameExtractionService->method('extractBaseFrame')->willReturnOnConsecutiveCalls(...$frames);

        $storageHelper = $this->createStub(StorageAdapterHelper::class);
        $storageHelper->method('isS3CompatibleDisk')->willReturn(false);

        $service = new SermonVideoQualityAssessmentService($frameExtractionService, $storageHelper);

        $outcome = $service->assessAndRetainLocalPath($sermon, 'sermons/video.mp4', 'public');

        $this->assertSame(SermonVideoQualityStatus::Approved, $outcome['result']->status);
        $this->assertNull($outcome['localVideoPath']);
    }

    #[Test]
    public function assess_and_retain_local_path_handles_missing_file(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/missing.mp4']);

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willReturn(false);

        $storageHelper = $this->createStub(StorageAdapterHelper::class);
        $service = new SermonVideoQualityAssessmentService($frameExtractionService, $storageHelper);

        $outcome = $service->assessAndRetainLocalPath($sermon, 'sermons/missing.mp4', 'public');

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $outcome['result']->status);
        $this->assertSame('missing_video_file', $outcome['result']->reason);
        $this->assertNull($outcome['localVideoPath']);
    }

    #[Test]
    public function assess_and_retain_local_path_handles_exceptions_gracefully(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willThrowException(new \RuntimeException('fail'));

        $storageHelper = $this->createStub(StorageAdapterHelper::class);
        $service = new SermonVideoQualityAssessmentService($frameExtractionService, $storageHelper);

        $outcome = $service->assessAndRetainLocalPath($sermon, 'sermons/video.mp4', 'public');

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $outcome['result']->status);
        $this->assertNull($outcome['localVideoPath']);
    }

    /**
     * @param  list<string>  $frames
     */
    private function assessWithFrames(array $frames): SermonVideoQualityAssessmentResult
    {
        Storage::disk('public')->put('sermons/video.mp4', 'video');

        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willReturn(true);
        $frameExtractionService->method('ensureLocalVideoPath')->willReturn('local-video.mp4');
        $frameExtractionService->method('getVideoMetadata')->willReturn(['duration' => 120.0]);
        $frameExtractionService->method('extractBaseFrame')->willReturnOnConsecutiveCalls(...$frames);
        $frameExtractionService->method('cleanupDownloadedVideo');

        $storageHelper = $this->createStub(StorageAdapterHelper::class);
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
        } elseif ($style === 'gray') {
            $value = 96 + ($seed % 64);
            imagefilledrectangle($image, 0, 0, 63, 63, imagecolorallocate($image, $value, $value, $value));
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
