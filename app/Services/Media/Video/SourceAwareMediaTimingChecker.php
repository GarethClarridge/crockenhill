<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Exceptions\VideoProcessingException;
use Symfony\Component\Process\Process;

/**
 * Detect introduced timing damage; simultaneous-event tests separately prove content sync.
 *
 * The source is read only where the check compares it: around each span's edges and at the
 * source moment of each irregularity found in the output. A service recording is 3-12 GB on a
 * USB 2.0 drive, and scanning it whole cost each cut minutes (follow-up round 2026-10-13).
 */
class SourceAwareMediaTimingChecker
{
    /** How far either side of a compared moment the source is read: well past one picture or sound frame. */
    public const WINDOW_SECONDS = 2.0;

    /** How short of its window a windowed read may start or stop before the source is scanned whole instead. */
    private const COVERAGE_SLACK_SECONDS = 0.5;

    /** @var array<string, array{origin: float, duration: float, frames: array<string, list<array{time: float, duration: float}>>}> */
    private array $sources = [];

    /** @var array<string, array{origin: float, duration: float}|null> */
    private array $headers = [];

    public function duration(string $path): float
    {
        return $this->header($path)['duration'] ?? $this->probe($path)['duration'];
    }

    /** @param list<array{start_time: float, end_time: float}> $spans */
    public function validateSpans(string $source, array $spans): void
    {
        if ($spans === []) {
            throw new VideoProcessingException('Invalid section bounds: no selected spans');
        }

        $duration = $this->duration($source);
        $previousEnd = 0.0;
        foreach ($spans as $span) {
            $start = $span['start_time'];
            $end = $span['end_time'];
            if (! is_finite($start) || ! is_finite($end) || $start < 0 || $end <= $start
                || $start < $previousEnd || $end > $duration + 0.001) {
                throw new VideoProcessingException('Invalid section bounds: non-finite, unordered, overlapping or outside source');
            }
            $previousEnd = $end;
        }
    }

    /**
     * Scan every presentation timestamp (sorting reordered video packets). Source anomalies must match in the selected
     * interval; joining two intervals is an expected discontinuity in the source only.
     *
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @return array{passed: bool, expected_duration: float, checked_frames: int, source_anomalies: int}
     */
    public function check(string $source, string $output, array $spans): array
    {
        $result = $this->probe($output, cache: false);
        $input = $this->sourceAround($source, $spans, $result);
        $expected = array_sum(array_map(static fn (array $span): float => $span['end_time'] - $span['start_time'], $spans));
        $frames = 0;
        $sourceAnomalies = 0;
        foreach (['video', 'audio'] as $type) {
            $sourceFrames = array_merge(...array_map(static fn (array $piece): array => $piece[$type] ?? [], $input['pieces']));
            $outputFrames = $result['frames'][$type] ?? [];
            if ($sourceFrames === [] || $outputFrames === []) {
                throw new VideoProcessingException("Timing check: unreadable required {$type} stream");
            }
            $resolution = $this->resolution($sourceFrames);
            $tolerance = $resolution + ($type === 'audio' ? 1024 / 48000 : 0.001);
            $first = $outputFrames[0];
            $last = $outputFrames[count($outputFrames) - 1];
            // Gaps are found within each piece read: the step from one window to the next is not in the source.
            $sourceGaps = array_merge(...array_map(fn (array $piece): array => $this->anomalies($piece[$type] ?? [], $resolution), $input['pieces']));
            foreach ($this->anomalies($outputFrames, $this->resolution($outputFrames)) as $anomaly) {
                if ($type === 'video' && $this->isJoinRounding($anomaly, $spans, $resolution)) {
                    continue;
                }
                $sourceTime = $this->sourceTime($anomaly['time'], $spans);
                $explained = false;
                foreach ($sourceGaps as $gap) {
                    if (abs($gap['time'] - $sourceTime) <= $tolerance
                        && abs($gap['gap'] - $anomaly['gap']) <= $tolerance) {
                        $explained = true;
                        $sourceAnomalies++;
                        break;
                    }
                }
                if (! $explained) {
                    throw new VideoProcessingException(sprintf('Timing check: introduced %s discontinuity at %.6fs (%.6fs)', $type, $anomaly['time'], $anomaly['gap']));
                }
            }
            $sourceLast = $sourceFrames[count($sourceFrames) - 1];
            $lastSpan = $spans[count($spans) - 1];
            $sourceTail = max(0.0, $lastSpan['end_time'] - ($sourceLast['time'] + $sourceLast['duration']));
            $streamExpected = $expected - $sourceTail;
            // Container duration may itself omit an irregular final picture. Match the
            // selected source's real presentation end, allowing one boundary rounding.
            $sourceOverrun = abs($lastSpan['end_time'] - $input['duration']) <= $resolution
                ? max(0.0, $sourceLast['time'] + $sourceLast['duration'] - $input['duration']) : 0.0;
            $endTolerance = count($spans) * $tolerance + 1024 / 48000 + $sourceOverrun;
            if (abs($first['time']) > $tolerance
                || abs($last['time'] + $last['duration'] - $streamExpected) > $endTolerance) {
                // The measurements place the fault from the log alone: 974 and 1216 failed here
                // without them and did not reproduce.
                $size = @filesize($output);
                throw new VideoProcessingException(sprintf(
                    'Timing check: %s start or duration differs from selected sections (first frame at %.6fs, ends at %.6fs, expected %.6fs within %.6fs; %d frames; output %s bytes)',
                    $type, $first['time'], $last['time'] + $last['duration'], $streamExpected, $endTolerance, count($outputFrames), $size === false ? 'unknown' : (string) $size,
                ));
            }
            $frames += count($outputFrames);
        }

        return ['passed' => true, 'expected_duration' => $expected, 'checked_frames' => $frames, 'source_anomalies' => $sourceAnomalies];
    }

    /**
     * The source's frames where check() compares them, as pieces in time order: one per window read
     * around a span edge or an output irregularity, or the whole source in one piece when it has no
     * header duration (an unindexed WebM) or a window cannot be read in full.
     *
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  array{origin: float, duration: float, frames: array<string, list<array{time: float, duration: float}>>}  $output
     * @return array{duration: float, pieces: list<array<string, list<array{time: float, duration: float}>>>}
     */
    private function sourceAround(string $source, array $spans, array $output): array
    {
        $header = $this->header($source);

        if ($header === null) {
            return $this->whole($source);
        }

        $moments = array_merge(...array_map(static fn (array $span): array => [$span['start_time'], $span['end_time']], $spans));

        foreach ($output['frames'] as $frames) {
            foreach ($this->anomalies($frames, $this->resolution($frames)) as $anomaly) {
                $moments[] = $this->sourceTime($anomaly['time'], $spans);
            }
        }

        $pieces = [];

        foreach ($this->windows($moments, $header['duration']) as [$from, $to]) {
            // A window reaching the source's end reads to the end of the file: a final picture can sit past the header duration.
            $piece = $this->probe($source, cache: false, interval: [$header['origin'] + $from, $to < $header['duration'] ? $header['origin'] + $to : null])['frames'];

            if (! $this->covers($piece, $from, $to, $header['duration'])) {
                return $this->whole($source);
            }

            $pieces[] = $piece;
        }

        return ['duration' => $header['duration'], 'pieces' => $pieces];
    }

    /** @return array{duration: float, pieces: list<array<string, list<array{time: float, duration: float}>>>} */
    private function whole(string $source): array
    {
        $probe = $this->probe($source);

        return ['duration' => $probe['duration'], 'pieces' => [$probe['frames']]];
    }

    /**
     * Each moment widened by {@see self::WINDOW_SECONDS} and kept inside the source, overlapping windows merged.
     *
     * @param  list<float>  $moments
     * @return list<array{0: float, 1: float}>
     */
    private function windows(array $moments, float $duration): array
    {
        sort($moments);
        $windows = [];

        foreach ($moments as $moment) {
            $from = max(0.0, $moment - self::WINDOW_SECONDS);
            $to = min($duration, $moment + self::WINDOW_SECONDS);
            $last = count($windows) - 1;

            if ($last >= 0 && $from <= $windows[$last][1]) {
                $windows[$last][1] = max($windows[$last][1], $to);

                continue;
            }

            $windows[] = [$from, $to];
        }

        return $windows;
    }

    /**
     * Whether a windowed read holds both streams across its window. A seek lands on the picture
     * before the window's start; a source ending early stops short of its header duration.
     *
     * @param  array<string, list<array{time: float, duration: float}>>  $piece
     */
    private function covers(array $piece, float $from, float $to, float $duration): bool
    {
        foreach (['video', 'audio'] as $type) {
            $frames = $piece[$type] ?? [];

            if ($frames === []) {
                return false;
            }

            $last = $frames[count($frames) - 1];

            if ($frames[0]['time'] > $from + self::COVERAGE_SLACK_SECONDS
                || min($to, $duration) - self::COVERAGE_SLACK_SECONDS > $last['time'] + $last['duration']) {
                return false;
            }
        }

        return true;
    }

    /**
     * A file's start and duration from its container header, without reading its packets; null
     * when the header gives no duration (an unindexed WebM).
     *
     * @return array{origin: float, duration: float}|null
     */
    private function header(string $path): ?array
    {
        $identity = $path.'|'.@filesize($path).'|'.@filemtime($path);

        if (array_key_exists($identity, $this->headers)) {
            return $this->headers[$identity];
        }

        $process = new Process([
            (string) config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe'),
            '-v', 'error', '-show_entries', 'format=start_time,duration', '-of', 'json', $path,
        ]);
        $process->setTimeout(120)->run();
        /** @var array{format?: array{start_time?: string, duration?: string}}|null $data */
        $data = json_decode($process->getOutput(), true);

        if (! $process->isSuccessful() || ! is_array($data)) {
            throw new VideoProcessingException('Timing check: source or output cannot be probed: '.$path);
        }

        $origin = (float) ($data['format']['start_time'] ?? 0);
        $duration = is_numeric($data['format']['duration'] ?? null) ? (float) $data['format']['duration'] - max(0, $origin) : null;
        // Bound memory across a long-lived worker, as the packet cache does.
        $this->headers = [$identity => $duration !== null && is_finite($duration) && $duration > 0 ? ['origin' => $origin, 'duration' => $duration] : null];

        return $this->headers[$identity];
    }

    /** @param list<array{start_time: float, end_time: float}> $spans */
    private function sourceTime(float $time, array $spans): float
    {
        $cursor = 0.0;
        foreach ($spans as $span) {
            $duration = $span['end_time'] - $span['start_time'];
            if ($time <= $cursor + $duration) {
                return $span['start_time'] + $time - $cursor;
            }
            $cursor += $duration;
        }

        return $spans[count($spans) - 1]['end_time'];
    }

    /** @param list<array{time: float, duration: float}> $frames */
    private function resolution(array $frames): float
    {
        $durations = array_column($frames, 'duration');
        sort($durations);

        return max(0.000001, $durations[intdiv(count($durations), 2)]);
    }

    /**
     * @param  list<array{time: float, duration: float}>  $frames
     * @return list<array{time: float, gap: float}>
     */
    private function anomalies(array $frames, float $resolution): array
    {
        $anomalies = [];
        for ($index = 1; $index < count($frames); $index++) {
            $previous = $frames[$index - 1];
            $gap = $frames[$index]['time'] - $previous['time'] - min($previous['duration'], $resolution);
            // Matroska clocks round to milliseconds. Larger jumps are measurable,
            // including the documented 16ms picture defect within a selected interval.
            $epsilon = 0.002;
            if (abs($gap) > $epsilon) {
                $anomalies[] = ['time' => $frames[$index]['time'], 'gap' => $gap];
            }
        }

        return $anomalies;
    }

    /**
     * Paired concat may pad a section to its final picture boundary. Permit one
     * frame of rounding only at an actual join, never inside a selected span.
     *
     * @param  array{time: float, gap: float}  $anomaly
     * @param  list<array{start_time: float, end_time: float}>  $spans
     */
    private function isJoinRounding(array $anomaly, array $spans, float $resolution): bool
    {
        if ($anomaly['gap'] < 0 || $anomaly['gap'] > $resolution + 0.001) {
            return false;
        }
        $cursor = 0.0;
        foreach (array_slice($spans, 0, -1) as $span) {
            $cursor += $span['end_time'] - $span['start_time'];
            if (abs($anomaly['time'] - $cursor) <= $resolution + 0.001) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{0: float, 1: float|null}|null  $interval  Read only this stretch, in the file's own timestamps; no end reads to the end of the file
     * @return array{origin: float, duration: float, frames: array<string, list<array{time: float, duration: float}>>}
     */
    private function probe(string $path, bool $cache = true, ?array $interval = null): array
    {
        $identity = $path.'|'.@filesize($path).'|'.@filemtime($path);
        if ($cache && isset($this->sources[$identity])) {
            return $this->sources[$identity];
        }
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe'),
            '-v', 'error', '-show_packets', '-show_streams', '-show_format',
            '-show_entries', 'format=start_time,duration:stream=index,codec_type,sample_rate,avg_frame_rate:packet=stream_index,pts_time,duration_time',
            ...($interval === null ? [] : ['-read_intervals', sprintf('%.6f%%', $interval[0]).($interval[1] === null ? '' : sprintf('%.6f', $interval[1]))]),
            '-of', 'json', $path,
        ]);
        $process->setTimeout(600)->run();
        /** @var array{format?: array{start_time?: string, duration?: string}, streams?: list<array{index: int, codec_type: string, sample_rate?: string, avg_frame_rate?: string}>, packets?: list<array{stream_index: int, pts_time?: string, duration_time?: string}>}|null $data */
        $data = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($data)) {
            throw new VideoProcessingException('Timing check: source or output cannot be probed: '.$path);
        }
        $origin = (float) ($data['format']['start_time'] ?? 0);
        $streams = [];
        foreach ($data['streams'] ?? [] as $stream) {
            if (! in_array($stream['codec_type'], ['audio', 'video'], true)) {
                continue;
            }
            [$numerator, $denominator] = array_pad(explode('/', $stream['avg_frame_rate'] ?? '0/1'), 2, '1');
            $frameDuration = (float) $numerator > 0 ? (float) $denominator / (float) $numerator : 0.0;
            $streams[$stream['index']] = ['type' => $stream['codec_type'], 'duration' => $frameDuration];
        }
        $frames = [];
        $presentationEnd = 0.0;
        foreach ($data['packets'] ?? [] as $packet) {
            $stream = $streams[$packet['stream_index']] ?? null;
            if ($stream === null) {
                continue;
            }
            if (! is_numeric($packet['pts_time'] ?? null)) {
                throw new VideoProcessingException('Timing check: missing packet timestamp');
            }
            $time = (float) $packet['pts_time'] - $origin;
            // AAC pre-roll is hidden by the container edit list and is not audible content.
            if ($time < -0.001) {
                continue;
            }
            $duration = (float) ($packet['duration_time'] ?? $stream['duration']);
            $frames[$stream['type']][] = ['time' => $time, 'duration' => $duration];
            $presentationEnd = max($presentationEnd, $time + $duration);
        }
        foreach ($frames as &$streamFrames) {
            usort($streamFrames, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
        }
        unset($streamFrames);
        // Unindexed WebM recordings may omit container duration. The complete
        // packet scan already establishes their actual presentation extent.
        $duration = is_numeric($data['format']['duration'] ?? null)
            ? (float) $data['format']['duration'] - max(0, $origin)
            : $presentationEnd;
        if (! is_finite($duration) || $duration <= 0) {
            throw new VideoProcessingException('Timing check: source or output has no measurable duration: '.$path);
        }
        $result = ['origin' => $origin, 'duration' => $duration, 'frames' => $frames];
        if ($cache) {
            // Bound memory across a long-lived worker. One source serves all sections in a job.
            $this->sources = [$identity => $result];
        }

        return $result;
    }
}
