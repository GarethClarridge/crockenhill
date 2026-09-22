<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\VideoDeadPictureCoverage;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Measures how much of a recording's picture is dead — frozen or black — using
 * ffmpeg's own `freezedetect` and `blackdetect` over the whole file.
 *
 * Sampling a handful of frames and comparing them cannot tell a broken
 * recording from a static camera: a preacher who fills a small part of the
 * frame changes almost nothing between two frames 1.5 s apart. Duration
 * separates them completely — a real freeze runs for minutes, a live camera
 * never holds still for 20 s.
 *
 * **Whole file, not windows (operator ruling 2026-09-22).** A video is released
 * whole only when at least 75% of it has usable picture, which is a share of
 * the whole recording. Six 30 s windows could only estimate that: a 52 s black
 * opening killed one window and read as 17% dead when it was 3.7%. One pass at
 * one frame a second, scaled down, costs about 24 s for a 22-minute 1080p
 * recording.
 */
class VideoDeadPictureProbe
{
    use SanitizesLogData;

    /**
     * Measure the whole recording, or null when ffmpeg could not read it — which
     * the caller must treat as no evidence rather than a clean picture.
     */
    public function probe(string $localVideoPath, float $duration): ?VideoDeadPictureCoverage
    {
        if ($duration <= 0.0) {
            return null;
        }

        $log = $this->runDetectors($localVideoPath);

        return $log === null ? null : $this->measure($log, $duration);
    }

    /**
     * Read the detectors' log into dead intervals and merge them.
     *
     * `freezedetect` reports an end only when the freeze *ends*, so a recording
     * frozen to its end prints a start and nothing else. That open freeze counts
     * to the end of the recording; reading durations alone would score a
     * whole-service holding card as a healthy video.
     */
    public function measure(string $log, float $duration): VideoDeadPictureCoverage
    {
        $freezes = $this->freezeIntervals($log, $duration);
        $blacks = $this->blackIntervals($log, $duration);
        $dead = $this->union([...$freezes, ...$blacks]);

        return new VideoDeadPictureCoverage(
            durationSeconds: $duration,
            deadSeconds: $this->length($dead),
            freezeSeconds: $this->length($this->union($freezes)),
            blackSeconds: $this->length($this->union($blacks)),
            deadIntervals: $dead,
        );
    }

    /**
     * @return list<array{float, float}>
     */
    private function freezeIntervals(string $log, float $duration): array
    {
        preg_match_all('/freeze_(start|end):\s*([\d.]+)/', $log, $matches, PREG_SET_ORDER);

        $intervals = [];
        $openStart = null;

        foreach ($matches as [, $kind, $seconds]) {
            if ($kind === 'start') {
                $openStart = (float) $seconds;

                continue;
            }

            if ($openStart !== null) {
                $intervals[] = $this->clamp($openStart, (float) $seconds, $duration);
                $openStart = null;
            }
        }

        if ($openStart !== null) {
            $intervals[] = $this->clamp($openStart, $duration, $duration);
        }

        return $intervals;
    }

    /**
     * @return list<array{float, float}>
     */
    private function blackIntervals(string $log, float $duration): array
    {
        preg_match_all('/black_start:\s*([\d.]+)\s+black_end:\s*([\d.]+)/', $log, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $match): array => $this->clamp((float) $match[1], (float) $match[2], $duration),
            $matches,
        );
    }

    /**
     * @return array{float, float}
     */
    private function clamp(float $start, float $end, float $duration): array
    {
        $start = min(max(0.0, $start), $duration);

        return [$start, min(max($start, $end), $duration)];
    }

    /**
     * @param  list<array{float, float}>  $intervals
     * @return list<array{float, float}>
     */
    private function union(array $intervals): array
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($intervals as [$start, $end]) {
            if ($end <= $start) {
                continue;
            }

            $last = array_key_last($merged);

            if ($last !== null && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }

    /**
     * @param  list<array{float, float}>  $intervals
     */
    private function length(array $intervals): float
    {
        return round(array_sum(array_map(static fn (array $interval): float => $interval[1] - $interval[0], $intervals)), 3);
    }

    /**
     * @return string|null The detector log, or null when ffmpeg could not read the file
     */
    private function runDetectors(string $localVideoPath): ?string
    {
        $command = [
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-hide_banner',
            '-nostats',
            '-i', $localVideoPath,
            '-an',
            '-vf', $this->filterChain(),
            '-f', 'null',
            '-',
        ];

        $process = new Process($command);
        $process->setTimeout((float) config('media-processing.video_quality.probe.timeout_seconds', 900));

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('Video dead-picture probe failed to run', $this->sanitizeArrayForLog([
                'video_path' => $localVideoPath,
                'error' => $e->getMessage(),
            ]));

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::warning('Video dead-picture probe returned a failure', $this->sanitizeArrayForLog([
                'video_path' => $localVideoPath,
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]));

            return null;
        }

        // The detectors report on stderr at ffmpeg's default log level.
        return $process->getErrorOutput().$process->getOutput();
    }

    /**
     * Sampling at one frame a second is enough to see a picture that does not
     * move; scaling down first removes most of the decoding cost without
     * changing the verdict, because both detectors work on whole-frame
     * statistics.
     */
    private function filterChain(): string
    {
        $framesPerSecond = max(0.1, (float) config('media-processing.video_quality.probe.frames_per_second', 1.0));
        $noiseDb = (float) config('media-processing.video_quality.probe.freeze_noise_db', -60.0);
        $freezeMinSeconds = max(1.0, (float) config('media-processing.video_quality.probe.freeze_min_seconds', 20.0));
        $blackMinSeconds = max(1.0, (float) config('media-processing.video_quality.probe.black_min_seconds', 5.0));
        $blackPixelThreshold = (float) config('media-processing.video_quality.probe.black_pixel_threshold', 0.10);

        return sprintf(
            'fps=%s,scale=320:-2,freezedetect=n=%sdB:d=%s,blackdetect=d=%s:pix_th=%s',
            $framesPerSecond,
            $noiseDb,
            $freezeMinSeconds,
            $blackMinSeconds,
            $blackPixelThreshold,
        );
    }
}
