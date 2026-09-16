<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Media\Video;

use App\Services\Media\Video\VideoDeadPictureProbe;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The probe's two halves that decide a verdict without touching ffmpeg: where it
 * looks, and how it reads what the detectors print. Log samples are copied from
 * real runs over the quarantined historic videos (2026-09-16 calibration).
 */
class VideoDeadPictureProbeTest extends TestCase
{
    private VideoDeadPictureProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media-processing.video_quality.probe.window_count' => 6,
            'media-processing.video_quality.probe.window_seconds' => 30.0,
        ]);

        $this->probe = new VideoDeadPictureProbe;
    }

    /**
     * A recording that is dead to the end of the window prints a freeze start
     * and no duration at all. Reading durations alone scores it zero — exactly
     * like a healthy video — which is how whole-recording failures could look
     * identical to good ones.
     */
    #[Test]
    public function a_freeze_still_open_at_the_end_of_the_window_counts_to_the_window_end(): void
    {
        $log = '[freezedetect @ 0xaaaadefd4540] lavfi.freezedetect.freeze_start: 0';

        $window = $this->probe->measureWindow($log, 120.0, 30.0);

        $this->assertSame(30.0, $window->freezeSeconds);
        $this->assertSame(0.0, $window->blackSeconds);
        $this->assertTrue($window->isDead(0.5));
    }

    #[Test]
    public function closed_freezes_are_summed_from_their_reported_durations(): void
    {
        $log = <<<'LOG'
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_start: 1
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_duration: 8
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_end: 9
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_start: 12
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_duration: 6.5
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_end: 18.5
        LOG;

        $window = $this->probe->measureWindow($log, 0.0, 30.0);

        $this->assertSame(14.5, $window->freezeSeconds);
        $this->assertFalse($window->isDead(0.5));
    }

    #[Test]
    public function black_time_is_read_from_the_black_detector(): void
    {
        $log = '[blackdetect @ 0xffff7c0429c0] black_start:0 black_end:29 black_duration:29';

        $window = $this->probe->measureWindow($log, 400.0, 30.0);

        $this->assertSame(29.0, $window->blackSeconds);
        $this->assertSame(29.0, $window->deadSeconds());
        $this->assertTrue($window->isDead(0.5));
    }

    /**
     * Ordinary preaching under a static camera, and dimly lit staging: neither
     * detector fires, which is the whole point of measuring duration rather
     * than appearance.
     */
    #[Test]
    public function a_window_with_no_detections_is_not_dead(): void
    {
        $log = "frame=   30 fps=0.0 q=-0.0 Lsize=N/A time=00:00:30.00 bitrate=N/A speed= 118x\n";

        $window = $this->probe->measureWindow($log, 60.0, 30.0);

        $this->assertSame(0.0, $window->freezeSeconds);
        $this->assertSame(0.0, $window->blackSeconds);
        $this->assertFalse($window->isDead(0.5));
    }

    #[Test]
    public function dead_time_never_exceeds_the_window_it_was_measured_in(): void
    {
        $log = <<<'LOG'
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_duration: 900
        [blackdetect @ 0xaaaa] black_start:0 black_end:900 black_duration:900
        LOG;

        $window = $this->probe->measureWindow($log, 0.0, 30.0);

        $this->assertSame(30.0, $window->freezeSeconds);
        $this->assertSame(30.0, $window->blackSeconds);
    }

    /**
     * The windows must reach both ends of the recording. Sampling the middle
     * only cannot tell a recording that is dead throughout from one that dies
     * part way in, and that distinction is what decides whether a verdict may
     * hide the video.
     */
    #[Test]
    public function windows_span_the_whole_recording_from_its_first_second_to_its_last(): void
    {
        $plan = $this->probe->windowPlan(1800.0);

        $this->assertCount(6, $plan);
        $this->assertSame(0.0, $plan[0][0]);
        $this->assertSame(1770.0, $plan[5][0]);
        $this->assertEqualsWithDelta(1800.0, $plan[5][0] + $plan[5][1], 0.001);

        foreach ($plan as [$start, $length]) {
            $this->assertSame(30.0, $length);
            $this->assertLessThanOrEqual(1800.0, $start + $length);
        }
    }

    #[Test]
    public function a_recording_shorter_than_one_window_is_measured_once_over_its_whole_length(): void
    {
        $plan = $this->probe->windowPlan(18.0);

        $this->assertSame([[0.0, 18.0]], $plan);
    }

    #[Test]
    public function a_recording_with_no_duration_has_nothing_to_measure(): void
    {
        $this->assertSame([], $this->probe->windowPlan(0.0));
    }
}
