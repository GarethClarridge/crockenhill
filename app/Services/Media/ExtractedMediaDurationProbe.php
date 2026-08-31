<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Jobs\ExtractSermon;
use App\Services\HistoricMedia\HistoricVideoSermonDurationRepair;
use App\Services\Processing\StorageAdapterHelper;
use FFMpeg\FFProbe;
use RuntimeException;

/**
 * Read the duration a media file actually holds, rather than the duration some
 * plan requested of it.
 *
 * FFmpeg emits less than it was asked for whenever a requested span runs past
 * source EOF, so a planned span is a request and this is the answer. The probe
 * lives in one place because two callers need the same answer from the same
 * binaries: {@see ExtractSermon} measures the video it has just
 * written, and {@see HistoricVideoSermonDurationRepair}
 * measures a durable asset banked by an earlier run that predates that
 * measurement.
 *
 * Resolution goes through {@see StorageAdapterHelper::createFFMpeg()} because it
 * is the only builder that checks both binaries exist and are executable. That
 * method returns null under `testing`, so tests bind an FFProbe double rather
 * than shelling out.
 */
class ExtractedMediaDurationProbe
{
    public function __construct(
        private readonly StorageAdapterHelper $storageHelper,
        private readonly ?FFProbe $ffprobe = null,
    ) {}

    /**
     * @throws RuntimeException When the file cannot be probed or holds no media.
     */
    public function durationOf(string $absolutePath): float
    {
        $ffprobe = $this->ffprobe ?? $this->storageHelper->createFFMpeg()?->getFFProbe();

        if (! $ffprobe instanceof FFProbe) {
            throw new RuntimeException("FFprobe is unavailable to measure the extracted media: {$absolutePath}");
        }

        try {
            $durationValue = $ffprobe->format($absolutePath)->get('duration');
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                "Unable to read the extracted media duration: {$absolutePath}",
                0,
                $exception
            );
        }

        if (! is_numeric($durationValue)) {
            throw new RuntimeException("FFprobe returned an unreadable duration for the extracted media: {$absolutePath}");
        }

        $duration = (float) $durationValue;

        if (! is_finite($duration) || $duration <= 0.0) {
            throw new RuntimeException("The extracted media must have a positive duration: {$absolutePath}");
        }

        return $duration;
    }
}
