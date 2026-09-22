<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Data\VideoDeadPictureCoverage;
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
 * The verdict rule over whole-recording measurements (operator ruling
 * 2026-09-22): a video is released whole or not at all. Under 75% usable
 * picture it is hidden and the sermon goes out audio-only; otherwise it is
 * released whole, flagged when any of it is dead. The cases are the
 * source-reviewed historic sermons from `vq-20260916-approvals-adjudication.json`.
 */
class SermonVideoQualityAssessmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['media-processing.video_quality.thresholds.release_usable_share' => 0.75]);
    }

    /**
     * The whole-recording black screens: nothing to watch, so the video is
     * hidden and the sermon is released audio-only.
     */
    #[Test]
    public function a_recording_black_from_start_to_finish_is_rejected(): void
    {
        $result = $this->assessWith($this->coverage(1800.0, black: [[0.0, 1800.0]]));

        $this->assertSame(SermonVideoQualityStatus::Rejected, $result->status);
        $this->assertSame('mostly_black', $result->reason);
        $this->assertSame(0.0, $result->usableShare);
    }

    /**
     * An OBS "starting soon" or camera-fault card for the whole service: never
     * black, but the picture never changes either.
     */
    #[Test]
    public function a_holding_card_for_the_whole_recording_is_rejected_as_frozen(): void
    {
        $result = $this->assessWith($this->coverage(1800.0, freeze: [[0.0, 1800.0]]));

        $this->assertSame(SermonVideoQualityStatus::Rejected, $result->status);
        $this->assertSame('frozen_frames', $result->reason);
    }

    /**
     * Sermon 930: an 85 s black opening in a 33-minute recording. Released
     * whole, with the video-issues flag, and nothing waiting for a person.
     */
    #[Test]
    public function a_black_opening_is_released_whole_and_flagged(): void
    {
        $result = $this->assessWith($this->coverage(1962.0, black: [[2.0, 87.2]], freeze: [[2.0, 87.2]]));

        $this->assertSame(SermonVideoQualityStatus::Approved, $result->status);
        $this->assertSame('partially_black', $result->reason);
        $this->assertEqualsWithDelta(0.9566, $result->usableShare, 0.0001);
    }

    /**
     * Sermon 1230: a camera-fault card twice, 30 s each. Frozen, not black.
     */
    #[Test]
    public function a_camera_fault_card_mid_sermon_is_released_whole_and_flagged(): void
    {
        $result = $this->assessWith($this->coverage(1940.0, freeze: [[993.0, 1023.0], [1324.0, 1354.0]]));

        $this->assertSame(SermonVideoQualityStatus::Approved, $result->status);
        $this->assertSame('partially_frozen', $result->reason);
    }

    /**
     * The ruling's boundary: exactly 75% usable is released, just under it is
     * hidden. Neither is trimmed.
     */
    #[Test]
    public function exactly_the_release_share_is_released(): void
    {
        $atShare = $this->assessWith($this->coverage(1000.0, black: [[0.0, 250.0]]));
        $underShare = $this->assessWith($this->coverage(1000.0, black: [[0.0, 251.0]]));

        $this->assertSame(SermonVideoQualityStatus::Approved, $atShare->status);
        $this->assertSame('partially_black', $atShare->reason);
        $this->assertSame(SermonVideoQualityStatus::Rejected, $underShare->status);
        $this->assertSame('mostly_black', $underShare->reason);
    }

    /**
     * No automatic verdict waits for review any more: the flag records the
     * imperfection instead of asking for a decision.
     */
    #[Test]
    public function no_verdict_is_left_for_review(): void
    {
        foreach ([0.0, 100.0, 400.0, 900.0, 1800.0] as $deadSeconds) {
            $result = $this->assessWith($this->coverage(1800.0, black: $deadSeconds > 0 ? [[0.0, $deadSeconds]] : []));

            $this->assertNotSame(SermonVideoQualityStatus::NeedsReview, $result->status);
        }
    }

    /**
     * Static-camera and dimly lit preaching: neither detector fires, so the
     * video is approved with no flag.
     */
    #[Test]
    public function preaching_with_no_dead_picture_is_approved_clean(): void
    {
        $result = $this->assessWith($this->coverage(1800.0));

        $this->assertSame(SermonVideoQualityStatus::Approved, $result->status);
        $this->assertNull($result->reason);
        $this->assertSame(1.0, $result->usableShare);
    }

    #[Test]
    public function the_dead_intervals_are_recorded_as_evidence(): void
    {
        $result = $this->assessWith($this->coverage(1314.0, black: [[2.0, 179.0]], freeze: [[2.0, 179.0], [222.0, 289.0]]));

        $this->assertSame([[2.0, 179.0], [222.0, 289.0]], $result->metrics['dead_intervals']);
        $this->assertSame(244.0, $result->deadSeconds);
        $this->assertSame(1314.0, $result->durationSeconds);
    }

    /**
     * Nothing measured is no evidence, not a clean picture.
     */
    #[Test]
    public function a_recording_whose_picture_cannot_be_measured_is_left_unassessed(): void
    {
        $result = $this->assessWith(null);

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
            ->willReturn($this->coverage(1905.25));

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
            $this->probeStub($this->coverage(1800.0)),
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
            $this->probeStub($this->coverage(1800.0)),
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

    private function assessWith(?VideoDeadPictureCoverage $coverage): SermonVideoQualityAssessmentResult
    {
        Storage::disk('public')->put('sermons/video.mp4', 'video');

        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $service = new SermonVideoQualityAssessmentService(
            $this->frameExtractionStub($coverage->durationSeconds ?? 1800.0),
            $this->storageHelperStub(),
            $this->probeStub($coverage),
        );

        return $service->assess($sermon, 'sermons/video.mp4', 'public');
    }

    /**
     * Build a coverage through the probe's own parser, so the union of black and
     * freeze intervals is the real one.
     *
     * @param  list<array{float, float}>  $black
     * @param  list<array{float, float}>  $freeze
     */
    private function coverage(float $duration, array $black = [], array $freeze = []): VideoDeadPictureCoverage
    {
        $log = '';

        foreach ($freeze as [$start, $end]) {
            $log .= "lavfi.freezedetect.freeze_start: {$start}\nlavfi.freezedetect.freeze_end: {$end}\n";
        }

        foreach ($black as [$start, $end]) {
            $log .= "black_start:{$start} black_end:{$end}\n";
        }

        return (new VideoDeadPictureProbe)->measure($log, $duration);
    }

    private function probeStub(?VideoDeadPictureCoverage $coverage): VideoDeadPictureProbe
    {
        $probe = $this->createStub(VideoDeadPictureProbe::class);
        $probe->method('probe')->willReturn($coverage);

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
