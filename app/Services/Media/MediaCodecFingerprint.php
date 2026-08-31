<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * A compact description of a file's video/audio streams, for provenance.
 *
 * Shared because a historic import must be able to derive it from whichever copy
 * is cheapest to read. Reading the removable archive is the expensive one -- a
 * bulk pass that probes every source there pays that I/O on top of the copy it
 * already has to make -- so the single-source lane defers this until the
 * operation's staged copy exists and probes that instead. The bytes are
 * verified identical by size, so the answer is the same either way.
 */
class MediaCodecFingerprint
{
    public function for(string $absolutePath): ?string
    {
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        $cmd = escapeshellarg($ffprobePath)
            .' -v quiet -print_format json -show_streams '
            .escapeshellarg($absolutePath);

        $output = shell_exec($cmd);

        if (! is_string($output)) {
            return null;
        }

        /** @var array{streams?: list<array{codec_type?: string, codec_name?: string, width?: int, height?: int, r_frame_rate?: string, sample_rate?: string}>}|null $data */
        $data = json_decode($output, true);

        if (! is_array($data)) {
            return null;
        }

        $videoCodec = null;
        $audioCodec = null;
        $resolution = null;
        $fps = null;
        $sampleRate = null;

        foreach ($data['streams'] ?? [] as $stream) {
            $codecType = $stream['codec_type'] ?? null;

            if ($codecType === 'video' && $videoCodec === null) {
                $videoCodec = $stream['codec_name'] ?? null;
                $resolution = isset($stream['width'], $stream['height'])
                    ? "{$stream['width']}x{$stream['height']}"
                    : null;
                $fps = $stream['r_frame_rate'] ?? null;
            }

            if ($codecType === 'audio' && $audioCodec === null) {
                $audioCodec = $stream['codec_name'] ?? null;
                $sampleRate = $stream['sample_rate'] ?? null;
            }
        }

        return "{$videoCodec}:{$audioCodec}:{$resolution}:{$fps}:{$sampleRate}";
    }
}
