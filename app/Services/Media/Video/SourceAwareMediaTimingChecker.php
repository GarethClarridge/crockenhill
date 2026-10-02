<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Exceptions\VideoProcessingException;
use Symfony\Component\Process\Process;

/** Detect introduced timing damage; simultaneous-event tests separately prove content sync. */
class SourceAwareMediaTimingChecker
{
    /** @var array<string, array{origin: float, duration: float, frames: array<string, list<array{time: float, duration: float}>>}> */
    private array $sources = [];

    public function duration(string $path): float
    {
        return $this->probe($path)['duration'];
    }

    /** @param list<array{start_time: float, end_time: float}> $spans */
    public function validateSpans(string $source, array $spans): void
    {
        if ($spans === []) {
            throw new VideoProcessingException('Invalid section bounds: no selected spans');
        }

        $duration = $this->probe($source)['duration'];
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
        $input = $this->probe($source);
        $result = $this->probe($output, cache: false);
        $expected = array_sum(array_map(static fn (array $span): float => $span['end_time'] - $span['start_time'], $spans));
        $frames = 0;
        $sourceAnomalies = 0;
        foreach (['video', 'audio'] as $type) {
            $sourceFrames = $input['frames'][$type] ?? [];
            $outputFrames = $result['frames'][$type] ?? [];
            if ($sourceFrames === [] || $outputFrames === []) {
                throw new VideoProcessingException("Timing check: unreadable required {$type} stream");
            }
            $resolution = $this->resolution($sourceFrames);
            $tolerance = $resolution + ($type === 'audio' ? 1024 / 48000 : 0.001);
            $first = $outputFrames[0];
            $last = $outputFrames[count($outputFrames) - 1];
            $sourceGaps = $this->anomalies($sourceFrames, $resolution);
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
            if (abs($first['time']) > $tolerance
                || abs($last['time'] + $last['duration'] - $streamExpected) > count($spans) * $tolerance + 1024 / 48000 + $sourceOverrun) {
                throw new VideoProcessingException("Timing check: {$type} start or duration differs from selected sections");
            }
            $frames += count($outputFrames);
        }

        return ['passed' => true, 'expected_duration' => $expected, 'checked_frames' => $frames, 'source_anomalies' => $sourceAnomalies];
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

    /** @return array{origin: float, duration: float, frames: array<string, list<array{time: float, duration: float}>>} */
    private function probe(string $path, bool $cache = true): array
    {
        $identity = $path.'|'.@filesize($path).'|'.@filemtime($path);
        if ($cache && isset($this->sources[$identity])) {
            return $this->sources[$identity];
        }
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe'),
            '-v', 'error', '-show_packets', '-show_streams', '-show_format',
            '-show_entries', 'format=start_time,duration:stream=index,codec_type,sample_rate,avg_frame_rate:packet=stream_index,pts_time,duration_time',
            '-of', 'json', $path,
        ]);
        $process->setTimeout(600)->run();
        /** @var array{format?: array{start_time?: string, duration?: string}, streams?: list<array{index: int, codec_type: string, sample_rate?: string, avg_frame_rate?: string}>, packets?: list<array{stream_index: int, pts_time?: string, duration_time?: string}>}|null $data */
        $data = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($data) || ! is_numeric($data['format']['duration'] ?? null)) {
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
            $frames[$stream['type']][] = ['time' => $time, 'duration' => (float) ($packet['duration_time'] ?? $stream['duration'])];
        }
        foreach ($frames as &$streamFrames) {
            usort($streamFrames, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
        }
        unset($streamFrames);
        $result = ['origin' => $origin, 'duration' => (float) $data['format']['duration'] - max(0, $origin), 'frames' => $frames];
        if ($cache) {
            // Bound memory across a long-lived worker. One source serves all sections in a job.
            $this->sources = [$identity => $result];
        }

        return $result;
    }
}
