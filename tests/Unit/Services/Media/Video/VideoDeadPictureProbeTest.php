<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Media\Video;

use App\Services\Media\Video\VideoDeadPictureProbe;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How the probe reads what ffmpeg's detectors print over a whole recording,
 * without touching ffmpeg. The 941 log is copied from a real whole-file pass
 * over the quarantined historic video (2026-09-22).
 */
class VideoDeadPictureProbeTest extends TestCase
{
    private VideoDeadPictureProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = new VideoDeadPictureProbe;
    }

    /**
     * A black picture is also a frozen one, so both detectors report the same
     * stretch. Summing them would count 941's black opening twice; the dead
     * time is the union of the intervals.
     */
    #[Test]
    public function overlapping_black_and_freeze_intervals_are_counted_once(): void
    {
        $log = <<<'LOG'
        [freezedetect @ 0xaaab14ecc4d0] lavfi.freezedetect.freeze_start: 2
        [freezedetect @ 0xaaab14ecc4d0] lavfi.freezedetect.freeze_duration: 177
        [freezedetect @ 0xaaab14ecc4d0] lavfi.freezedetect.freeze_end: 179
        [blackdetect @ 0xffff78013540] black_start:2 black_end:179 black_duration:177
        [freezedetect @ 0xaaab14ecc4d0] lavfi.freezedetect.freeze_start: 222
        [freezedetect @ 0xaaab14ecc4d0] lavfi.freezedetect.freeze_duration: 67
        [freezedetect @ 0xaaab14ecc4d0] lavfi.freezedetect.freeze_end: 289
        LOG;

        $coverage = $this->probe->measure($log, 1314.0);

        $this->assertSame(244.0, $coverage->deadSeconds);
        $this->assertSame(177.0, $coverage->blackSeconds);
        $this->assertSame(244.0, $coverage->freezeSeconds);
        $this->assertSame([[2.0, 179.0], [222.0, 289.0]], $coverage->deadIntervals);
        $this->assertEqualsWithDelta(0.8143, $coverage->usableShare(), 0.0001);
    }

    /**
     * `freezedetect` reports an end only when the freeze ends, so a picture
     * frozen to the end of the file prints a start and nothing else. It counts
     * to the end of the recording, or a holding card for the whole service
     * would read as a healthy video.
     */
    #[Test]
    public function a_freeze_still_open_at_the_end_counts_to_the_end_of_the_recording(): void
    {
        $log = '[freezedetect @ 0xaaaadefd4540] lavfi.freezedetect.freeze_start: 0';

        $coverage = $this->probe->measure($log, 1800.0);

        $this->assertSame(1800.0, $coverage->deadSeconds);
        $this->assertSame(0.0, $coverage->usableShare());
    }

    /**
     * Ordinary preaching under a static camera and dimly lit staging: neither
     * detector fires, which is why duration rather than appearance is measured.
     */
    #[Test]
    public function a_recording_with_no_detections_is_wholly_usable(): void
    {
        $log = "frame= 1800 fps=0.0 q=-0.0 Lsize=N/A time=00:30:00.00 bitrate=N/A speed= 75x\n";

        $coverage = $this->probe->measure($log, 1800.0);

        $this->assertSame(0.0, $coverage->deadSeconds);
        $this->assertSame([], $coverage->deadIntervals);
        $this->assertSame(1.0, $coverage->usableShare());
    }

    #[Test]
    public function dead_time_never_exceeds_the_recording(): void
    {
        $log = '[blackdetect @ 0xaaaa] black_start:0 black_end:2000 black_duration:2000';

        $coverage = $this->probe->measure($log, 1800.0);

        $this->assertSame(1800.0, $coverage->deadSeconds);
        $this->assertSame([[0.0, 1800.0]], $coverage->deadIntervals);
    }

    #[Test]
    public function separate_stretches_are_summed(): void
    {
        $log = <<<'LOG'
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_start: 993
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_duration: 30
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_end: 1023
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_start: 1324
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_duration: 30
        [freezedetect @ 0xaaaa] lavfi.freezedetect.freeze_end: 1354
        LOG;

        $coverage = $this->probe->measure($log, 1940.0);

        $this->assertSame(60.0, $coverage->deadSeconds);
        $this->assertSame(0.0, $coverage->blackSeconds);
    }
}
