<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\VideoDeadPictureWindow;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Measures how long a recording's picture is dead — frozen or black — using
 * ffmpeg's own `freezedetect` and `blackdetect` over windows spread across the
 * whole file.
 *
 * Sampling a handful of frames and comparing them cannot tell a broken
 * recording from a static camera: a preacher who fills a small part of the
 * frame changes almost nothing between two frames 1.5 s apart, while a dead
 * picture and a live one both look plausible in isolation. Duration separates
 * them completely — a real freeze runs for minutes, a live camera never holds
 * still for 20 s — and windows across the whole recording distinguish a file
 * that is dead throughout from one that dies part way in.
 */
class VideoDeadPictureProbe
{
    use SanitizesLogData;

    /**
     * Measure every window of the recording.
     *
     * @return list<VideoDeadPictureWindow> Empty when nothing could be measured,
     *                                      which the caller must treat as no evidence rather than a clean picture.
     */
    public function probe(string $localVideoPath, float $duration): array
    {
        $windows = [];

        foreach ($this->windowPlan($duration) as [$start, $length]) {
            $log = $this->runDetectors($localVideoPath, $start, $length);

            if ($log === null) {
                continue;
            }

            $windows[] = $this->measureWindow($log, $start, $length);
        }

        return $windows;
    }

    /**
     * Window starts and lengths, evenly spaced from the first second of the
     * recording to its last.
     *
     * Covering both ends is the point: the recordings that are genuinely
     * unusable are dead from start to finish, and the one that dies part way in
     * must show live windows before the dead ones, or it cannot be told apart.
     *
     * @return list<array{float, float}>
     */
    public function windowPlan(float $duration): array
    {
        if ($duration <= 0.0) {
            return [];
        }

        $count = max(1, (int) config('media-processing.video_quality.probe.window_count', 6));
        $windowSeconds = max(1.0, (float) config('media-processing.video_quality.probe.window_seconds', 30.0));
        $latestStart = max(0.0, $duration - $windowSeconds);

        if ($count === 1 || $latestStart <= 0.0) {
            return [[0.0, min($windowSeconds, $duration)]];
        }

        $plan = [];

        for ($index = 0; $index < $count; $index++) {
            $start = round($latestStart * $index / ($count - 1), 3);
            $plan[] = [$start, round(min($windowSeconds, $duration - $start), 3)];
        }

        return $plan;
    }

    /**
     * Read one window's ffmpeg log into a measurement.
     *
     * `freezedetect` reports a duration only when the freeze *ends*, so a
     * recording that is frozen to the end of the window prints a start and
     * nothing else. Counting that open freeze to the window end is what makes
     * the whole-recording failures measurable at all — reading durations alone
     * scores them zero, exactly like a healthy video.
     */
    public function measureWindow(string $log, float $start, float $length): VideoDeadPictureWindow
    {
        preg_match_all('/freeze_start:\s*([\d.]+)/', $log, $freezeStarts);
        preg_match_all('/freeze_duration:\s*([\d.]+)/', $log, $freezeDurations);
        preg_match_all('/black_duration:\s*([\d.]+)/', $log, $blackDurations);

        $freezeSeconds = array_sum(array_map('floatval', $freezeDurations[1]));

        if (count($freezeStarts[1]) > count($freezeDurations[1])) {
            $openFreezeStart = (float) end($freezeStarts[1]);
            $freezeSeconds += max(0.0, $length - $openFreezeStart);
        }

        return new VideoDeadPictureWindow(
            start: $start,
            length: $length,
            freezeSeconds: min($length, $freezeSeconds),
            blackSeconds: min($length, array_sum(array_map('floatval', $blackDurations[1]))),
        );
    }

    /**
     * @return string|null The detector log, or null when ffmpeg could not read the window
     */
    private function runDetectors(string $localVideoPath, float $start, float $length): ?string
    {
        $command = [
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-hide_banner',
            '-nostats',
            // Input seek: ffmpeg jumps to the window instead of decoding up to it.
            '-ss', (string) round($start, 3),
            '-t', (string) round($length, 3),
            '-i', $localVideoPath,
            '-an',
            '-vf', $this->filterChain(),
            '-f', 'null',
            '-',
        ];

        $process = new Process($command);
        $process->setTimeout((float) config('media-processing.video_quality.probe.timeout_seconds', 120));

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('Video dead-picture probe failed to run', $this->sanitizeArrayForLog([
                'video_path' => $localVideoPath,
                'window_start' => $start,
                'error' => $e->getMessage(),
            ]));

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::warning('Video dead-picture probe returned a failure', $this->sanitizeArrayForLog([
                'video_path' => $localVideoPath,
                'window_start' => $start,
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
     * move, and keeps a window's cost to a second or two of decoding; scaling
     * down first removes most of that cost again without changing the verdict,
     * because both detectors work on whole-frame statistics.
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
