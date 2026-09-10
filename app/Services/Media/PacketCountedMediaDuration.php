<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Services\Media\Video\HistoricVideoCurationDraft;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The length of a recording whose container does not declare one.
 *
 * The 2020–2025 corpus contains WebM pulled down as YouTube backups, and a live
 * capture carries no duration in either the format or the stream header —
 * `ffprobe` answers `N/A` in every field, including `bit_rate` and
 * `duration_ts`. Counting video packets and dividing by the frame rate recovers
 * the real length for about a second per file, because ffprobe reads packet
 * headers rather than decoding, and it reproduces the operator's hand-measured
 * durations exactly.
 *
 * This is the same measurement curation has used since the corpus was drafted,
 * lifted out of {@see HistoricVideoCurationDraft} so
 * that P8-Q17's bounds check can reach it. It answers in seconds; curation's
 * worksheet divides for its own minutes.
 *
 * Null, never a guess, when the file cannot be read or reports nothing usable:
 * an absent duration and a wrong one are very different answers to give a screen
 * that clamps section bounds.
 */
class PacketCountedMediaDuration
{
    public function seconds(string $absolutePath): ?float
    {
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffprobe_path'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-count_packets',
            '-show_entries', 'stream=nb_read_packets,avg_frame_rate',
            '-of', 'default=noprint_wrappers=1',
            $absolutePath,
        ]);

        try {
            $process->setTimeout(300)->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $values = [];

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', trim($line), 2);
                $values[$key] = $value;
            }
        }

        $packets = $values['nb_read_packets'] ?? null;
        $frameRate = $values['avg_frame_rate'] ?? null;

        if (! is_numeric($packets) || ! is_string($frameRate) || ! str_contains($frameRate, '/')) {
            return null;
        }

        [$numerator, $denominator] = array_map('floatval', explode('/', $frameRate, 2));

        if ($denominator <= 0.0 || $numerator <= 0.0 || (float) $packets <= 0.0) {
            return null;
        }

        return (float) $packets / ($numerator / $denominator);
    }
}
