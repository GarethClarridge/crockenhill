<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\VideoProcessingException;
use FFMpeg\FFMpeg;

/**
 * Provides a nullable $ffmpeg property and a requireFfmpeg() guard for services
 * that wrap FFmpeg operations. Pair with StorageAdapterHelper::createFFMpeg()
 * to initialize $ffmpeg in the constructor.
 */
trait RequiresFfmpeg
{
    /** @phpstan-ignore-next-line property.unusedType (nullable for testing environment) */
    private ?FFMpeg $ffmpeg;

    private function requireFfmpeg(): FFMpeg
    {
        if (! $this->ffmpeg instanceof FFMpeg) {
            throw new VideoProcessingException('FFmpeg is unavailable in the current environment.');
        }

        return $this->ffmpeg;
    }
}
