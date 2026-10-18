<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\VideoProcessingException;
use App\Services\Media\Video\SourceAwareMediaTimingChecker;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SourceAwareMediaTimingCheckerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cut-timing-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->directory);
        $probe = $this->directory.'/ffprobe';
        // Files are named by what they hold: `len=60` seconds at 30 fps (default 1), `jump=41` shifts the
        // pictures from that second on by 16 ms (`damaged`: from the third), `no-duration` omits the
        // container duration and `noseek` returns nothing for a windowed read. Every call is logged.
        File::put($probe, <<<'PROBE'
#!/usr/bin/env php
<?php
$path = end($argv);
file_put_contents(dirname(__FILE__).'/calls.log', json_encode(array_slice($argv, 1))."\n", FILE_APPEND);
$name = basename($path);
$length = preg_match('/len=(\d+)/', $name, $m) ? (int) $m[1] : 1;
$jumpAt = preg_match('/jump=(\d+)/', $name, $m) ? (float) $m[1] : (str_contains($name, 'damaged') ? 2 / 30 : null);
$format = ['start_time' => '0'];
if (! str_contains($name, 'no-duration')) { $format['duration'] = (string) $length; }
$streams = [['index' => 0, 'codec_type' => 'video', 'avg_frame_rate' => '30/1'], ['index' => 1, 'codec_type' => 'audio']];
if (! in_array('-show_packets', $argv, true)) { echo json_encode(['format' => $format, 'streams' => $streams]); exit; }
$intervals = null;
$at = array_search('-read_intervals', $argv, true);
if ($at !== false) {
    $intervals = array_map(static fn (string $interval): array => array_map(static fn (string $bound): float => $bound === '' ? INF : (float) $bound, explode('%', $interval)), explode(',', $argv[$at + 1]));
}
$wanted = static fn (float $time): bool => $intervals === null || array_any($intervals, static fn (array $interval): bool => $time >= $interval[0] - 0.5 && $time < $interval[1]);
$packets = [];
for ($i = 0; $i < $length * 30; $i++) {
    if (! $wanted($i / 30) || ($intervals !== null && str_contains($name, 'noseek'))) { continue; }
    $packets[] = ['stream_index' => 0, 'pts_time' => (string) ($i / 30 + ($jumpAt !== null && $i / 30 >= $jumpAt - 0.0001 ? 0.016 : 0)), 'duration_time' => (string) (1 / 30)];
    $packets[] = ['stream_index' => 1, 'pts_time' => (string) ($i / 30), 'duration_time' => (string) (1 / 30)];
}
echo json_encode(['format' => $format, 'streams' => $streams, 'packets' => $packets]);
PROBE);
        chmod($probe, 0755);
        config(['media-processing.ffmpeg.ffprobe_path' => $probe]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_rejects_the_documented_sixteen_millisecond_picture_jump(): void
    {
        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('introduced video discontinuity');
        app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source', $this->directory.'/damaged', [['start_time' => 0.0, 'end_time' => 1.0]]);
    }

    #[Test]
    public function it_accepts_the_same_picture_irregularity_when_present_in_the_source(): void
    {
        $report = app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/damaged-source', $this->directory.'/damaged-output', [['start_time' => 0.0, 'end_time' => 1.0]]);
        $this->assertTrue($report['passed']);
        $this->assertSame(1, $report['source_anomalies']);
    }

    #[Test]
    public function one_frame_of_picture_rounding_is_allowed_only_at_a_selected_join(): void
    {
        $report = app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source', $this->directory.'/damaged', [
            ['start_time' => 0.0, 'end_time' => 2 / 30],
            ['start_time' => 2 / 30, 'end_time' => 1.0],
        ]);
        $this->assertTrue($report['passed']);
    }

    #[Test]
    public function an_unindexed_webm_uses_observed_packet_extent_when_container_duration_is_absent(): void
    {
        $checker = app(SourceAwareMediaTimingChecker::class);
        $source = $this->directory.'/no-duration-source';
        $spans = [['start_time' => 0.0, 'end_time' => 1.0]];
        $this->assertEqualsWithDelta(1.0, $checker->duration($source), 0.000001);
        $checker->validateSpans($source, $spans);
        $this->assertTrue($checker->check($source, $this->directory.'/output', $spans)['passed']);
    }

    #[Test]
    public function it_reads_a_long_source_only_around_its_spans(): void
    {
        $report = app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source-len=60', $this->directory.'/output-len=4', $this->spans());

        $this->assertTrue($report['passed']);
        $this->assertSame([], array_filter($this->sourcePacketReads('source-len=60'), static fn (array $call): bool => ! in_array('-read_intervals', $call, true)));
    }

    #[Test]
    public function a_gap_in_the_source_still_excuses_the_same_gap_in_its_output(): void
    {
        $report = app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source-len=60-jump=41', $this->directory.'/output-len=4-jump=3', $this->spans());

        $this->assertTrue($report['passed']);
        $this->assertSame(1, $report['source_anomalies']);
    }

    #[Test]
    public function a_gap_the_source_lacks_is_still_caught_when_the_source_is_read_in_windows(): void
    {
        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('introduced video discontinuity');

        app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source-len=60', $this->directory.'/output-len=4-jump=3', $this->spans());
    }

    #[Test]
    public function a_span_ending_with_its_source_reads_the_sources_real_end(): void
    {
        $report = app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source-len=60', $this->directory.'/output-len=2', [['start_time' => 58.0, 'end_time' => 60.0]]);

        $this->assertTrue($report['passed']);
    }

    #[Test]
    public function a_source_that_cannot_be_read_in_windows_is_scanned_whole(): void
    {
        $report = app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source-len=60-noseek', $this->directory.'/output-len=4', $this->spans());

        $this->assertTrue($report['passed']);
        $this->assertNotSame([], array_filter($this->sourcePacketReads('source-len=60-noseek'), static fn (array $call): bool => ! in_array('-read_intervals', $call, true)));
    }

    /**
     * 974 and 1216 failed candidate preparation with this message alone, and neither failure
     * reproduced: the measured values have to be in the message for the log to place the fault.
     */
    #[Test]
    public function a_short_output_reports_what_it_measured(): void
    {
        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('Timing check: video start or duration differs from selected sections (first frame at 0.000000s, ends at 1.000000s, expected 2.000000s');

        app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source-len=60', $this->directory.'/output-len=1', [['start_time' => 10.0, 'end_time' => 12.0]]);
    }

    #[Test]
    public function span_bounds_are_checked_against_the_header_without_reading_packets(): void
    {
        $checker = app(SourceAwareMediaTimingChecker::class);
        $checker->validateSpans($this->directory.'/source-len=60', $this->spans());

        $this->assertSame([], $this->sourcePacketReads('source-len=60'));
        $this->expectException(VideoProcessingException::class);
        $checker->validateSpans($this->directory.'/source-len=60', [['start_time' => 58.0, 'end_time' => 61.0]]);
    }

    /** @return list<array{start_time: float, end_time: float}> */
    private function spans(): array
    {
        return [['start_time' => 10.0, 'end_time' => 12.0], ['start_time' => 40.0, 'end_time' => 42.0]];
    }

    /** @return list<list<string>> The logged ffprobe calls that read a file's packets */
    private function sourcePacketReads(string $name): array
    {
        $calls = array_map(static fn (string $line): array => json_decode($line, true), file($this->directory.'/calls.log', FILE_IGNORE_NEW_LINES) ?: []);

        return array_values(array_filter($calls, static fn (array $call): bool => basename((string) end($call)) === $name && in_array('-show_packets', $call, true)));
    }
}
