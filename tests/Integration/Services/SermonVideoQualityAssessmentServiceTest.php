<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Data\VideoDeadPictureWindow;
use App\Enums\SermonVideoQualityStatus;
use App\Models\Sermon;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\SermonVideoQualityAssessmentService;
use App\Services\Media\Video\VideoDeadPictureProbe;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The verdict rules, over the window measurements the probe produces.
 *
 * The window shapes here are the calibrated classes from the 48 historic
 * rejections (plan §4.1b/§4.3a, `storage/scratch/vq-20260916-windows.json`):
 * whole-recording black screens and holding cards read dead in 6 of 6 windows,
 * static-camera and dim-light preaching in 0 of 6, and the one recording that
 * fails part way through (sermon 1225) in 2 of 6.
 */
class SermonVideoQualityAssessmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * The 12 black recordings and 7 holding cards: dead from the first window
     * to the last, so hiding them is safe.
     */
    #[Test]
    public function a_recording_black_from_start_to_finish_is_rejected(): void
    {
        $result = $this->assessWithWindows($this->deadWindows(6, black: true));

        $this->assertSame(SermonVideoQualityStatus::Rejected, $result->status);
        $this->assertSame('mostly_black', $result->reason);
        $this->assertSame(1.0, $result->deadWindowRatio);
    }

    /**
     * An OBS "service starting soon" or camera-fault card: never black, but the
     * picture never changes either.
     */
    #[Test]
    public function a_holding_card_for_the_whole_recording_is_rejected_as_frozen(): void
    {
        $result = $this->assessWithWindows($this->deadWindows(6, black: false));

        $this->assertSame(SermonVideoQualityStatus::Rejected, $result->status);
        $this->assertSame('frozen_frames', $result->reason);
        $this->assertSame(6, $result->deadWindowCount);
    }

    /**
     * Sermon 1225: about eight minutes of preaching, then a camera-fault card.
     * A rejection would hide preaching that people can watch, so a partly dead
     * recording goes to a person instead of being withheld automatically.
     */
    #[Test]
    public function a_recording_that_fails_part_way_through_is_flagged_for_review_not_hidden(): void
    {
        $windows = [
            $this->liveWindow(0.0),
            $this->liveWindow(386.0),
            $this->liveWindow(772.0),
            $this->liveWindow(1158.0),
            new VideoDeadPictureWindow(start: 1544.0, length: 30.0, freezeSeconds: 30.0, blackSeconds: 0.0),
            new VideoDeadPictureWindow(start: 1930.0, length: 30.0, freezeSeconds: 30.0, blackSeconds: 0.0),
        ];

        $result = $this->assessWithWindows($windows);

        $this->assertSame(SermonVideoQualityStatus::NeedsReview, $result->status);
        $this->assertSame('partially_frozen', $result->reason);
        $this->assertEqualsWithDelta(0.333, $result->deadWindowRatio, 0.001);
    }

    /**
     * The 25 static-camera recordings the old detector hid: a preacher who
     * fills a small part of the frame changes almost nothing between frames,
     * but the picture never holds still for 20 s.
     */
    #[Test]
    public function normal_preaching_under_a_static_camera_is_approved(): void
    {
        $result = $this->assessWithWindows($this->liveWindows(6));

        $this->assertSame(SermonVideoQualityStatus::Approved, $result->status);
        $this->assertNull($result->reason);
        $this->assertSame(0.0, $result->deadWindowRatio);
        $this->assertSame(0, $result->deadWindowCount);
    }

    /**
     * Sermons 903, 969 and 1307: dimly lit preaching that sat under the old
     * absolute brightness floor. The black detector does not fire on it, and
     * brightness is no longer consulted at all.
     */
    #[Test]
    public function dimly_lit_preaching_is_approved_because_no_black_time_is_measured(): void
    {
        $result = $this->assessWithWindows($this->liveWindows(6));

        $this->assertSame(SermonVideoQualityStatus::Approved, $result->status);
        $this->assertSame(0.0, $result->blackSeconds);
    }

    /**
     * A single dead window is a real finding — an outage, a card mid-service —
     * but far too little to hide a recording over.
     */
    #[Test]
    public function one_dead_window_among_many_is_reviewed_rather_than_rejected(): void
    {
        $windows = [
            ...$this->liveWindows(5),
            new VideoDeadPictureWindow(start: 1500.0, length: 30.0, freezeSeconds: 28.0, blackSeconds: 28.0),
        ];

        $result = $this->assessWithWindows($windows);

        $this->assertSame(SermonVideoQualityStatus::NeedsReview, $result->status);
        $this->assertSame('partially_black', $result->reason);
    }

    /**
     * Freeze and black overlap on a black picture, so the recording's dead time
     * is the longer measure, not the sum.
     */
    #[Test]
    public function the_measurement_of_every_window_is_recorded_as_evidence(): void
    {
        $result = $this->assessWithWindows($this->deadWindows(2, black: true));

        $this->assertCount(2, $result->metrics['windows']);
        $this->assertSame(30.0, $result->metrics['windows'][0]['freeze_seconds']);
        $this->assertSame(29.0, $result->metrics['windows'][0]['black_seconds']);
        $this->assertSame(60.0, $result->measuredSeconds);
    }

    /**
     * Nothing measured is no evidence, not a clean picture.
     */
    #[Test]
    public function a_recording_whose_picture_cannot_be_measured_is_left_unassessed(): void
    {
        $result = $this->assessWithWindows([]);

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $result->status);
        $this->assertSame('analysis_failed', $result->reason);
    }

    #[Test]
    public function the_whole_recording_duration_is_handed_to_the_probe(): void
    {
        Storage::disk('public')->put('sermons/video.mp4', 'video');

        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $probe = $this->createMock(VideoDeadPictureProbe::class);
        $probe->expects($this->once())
            ->method('probe')
            ->with('local-video.mp4', 1905.25)
            ->willReturn($this->liveWindows(6));

        $service = new SermonVideoQualityAssessmentService(
            $this->frameExtractionStub(1905.25),
            $this->storageHelperStub(),
            $probe,
        );

        $this->assertSame(SermonVideoQualityStatus::Approved, $service->assess($sermon, 'sermons/video.mp4', 'public')->status);
    }

    #[Test]
    public function service_errors_return_safe_analysis_failed_result(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willThrowException(new \RuntimeException('boom'));

        $service = new SermonVideoQualityAssessmentService(
            $frameExtractionService,
            $this->storageHelperStub(),
            $this->createStub(VideoDeadPictureProbe::class),
        );

        $result = $service->assess($sermon, 'sermons/video.mp4', 'public');

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $result->status);
        $this->assertSame('analysis_failed', $result->reason);
    }

    #[Test]
    public function assess_and_retain_local_path_returns_path_for_s3_disk(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $service = new SermonVideoQualityAssessmentService(
            $this->frameExtractionStub(videoPath: 'temp/downloaded.mp4'),
            $this->storageHelperStub(isS3: true),
            $this->probeStub($this->liveWindows(6)),
        );

        $outcome = $service->assessAndRetainLocalPath($sermon, 'sermons/video.mp4', 'do_spaces');

        $this->assertSame(SermonVideoQualityStatus::Approved, $outcome['result']->status);
        $this->assertSame('temp/downloaded.mp4', $outcome['localVideoPath']);
    }

    #[Test]
    public function assess_and_retain_local_path_returns_null_path_for_local_disk(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $service = new SermonVideoQualityAssessmentService(
            $this->frameExtractionStub(),
            $this->storageHelperStub(),
            $this->probeStub($this->liveWindows(6)),
        );

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

        $service = new SermonVideoQualityAssessmentService(
            $frameExtractionService,
            $this->storageHelperStub(),
            $this->createStub(VideoDeadPictureProbe::class),
        );

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

        $service = new SermonVideoQualityAssessmentService(
            $frameExtractionService,
            $this->storageHelperStub(),
            $this->createStub(VideoDeadPictureProbe::class),
        );

        $outcome = $service->assessAndRetainLocalPath($sermon, 'sermons/video.mp4', 'public');

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $outcome['result']->status);
        $this->assertNull($outcome['localVideoPath']);
    }

    /**
     * @param  list<VideoDeadPictureWindow>  $windows
     */
    private function assessWithWindows(array $windows): SermonVideoQualityAssessmentResult
    {
        Storage::disk('public')->put('sermons/video.mp4', 'video');

        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $service = new SermonVideoQualityAssessmentService(
            $this->frameExtractionStub(),
            $this->storageHelperStub(),
            $this->probeStub($windows),
        );

        return $service->assess($sermon, 'sermons/video.mp4', 'public');
    }

    /**
     * @return list<VideoDeadPictureWindow>
     */
    private function deadWindows(int $count, bool $black): array
    {
        $windows = [];

        for ($index = 0; $index < $count; $index++) {
            $windows[] = new VideoDeadPictureWindow(
                start: $index * 300.0,
                length: 30.0,
                freezeSeconds: 30.0,
                blackSeconds: $black ? 29.0 : 0.0,
            );
        }

        return $windows;
    }

    /**
     * @return list<VideoDeadPictureWindow>
     */
    private function liveWindows(int $count): array
    {
        $windows = [];

        for ($index = 0; $index < $count; $index++) {
            $windows[] = $this->liveWindow($index * 300.0);
        }

        return $windows;
    }

    private function liveWindow(float $start): VideoDeadPictureWindow
    {
        return new VideoDeadPictureWindow(start: $start, length: 30.0, freezeSeconds: 0.0, blackSeconds: 0.0);
    }

    /**
     * @param  list<VideoDeadPictureWindow>  $windows
     */
    private function probeStub(array $windows): VideoDeadPictureProbe
    {
        $probe = $this->createStub(VideoDeadPictureProbe::class);
        $probe->method('probe')->willReturn($windows);

        return $probe;
    }

    private function frameExtractionStub(float $duration = 1800.0, string $videoPath = 'local-video.mp4'): FrameExtractionService
    {
        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $frameExtractionService->method('videoFileExists')->willReturn(true);
        $frameExtractionService->method('ensureLocalVideoPath')->willReturn($videoPath);
        $frameExtractionService->method('getVideoMetadata')->willReturn(['duration' => $duration]);
        $frameExtractionService->method('cleanupDownloadedVideo');

        return $frameExtractionService;
    }

    private function storageHelperStub(bool $isS3 = false): StorageAdapterHelper
    {
        $storageHelper = $this->createStub(StorageAdapterHelper::class);
        $storageHelper->method('isS3CompatibleDisk')->willReturn($isS3);

        return $storageHelper;
    }
}
