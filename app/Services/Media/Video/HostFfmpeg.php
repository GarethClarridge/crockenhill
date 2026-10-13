<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Exceptions\VideoProcessingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * FFmpeg on the Mac, where VideoToolbox's hardware encoder is: scripts/ffmpeg_host_server.py.
 *
 * Paths are sent as this container sees them; the service maps them to the host's
 * and refuses any outside the roots it serves.
 */
class HostFfmpeg
{
    /**
     * @param  list<string>  $arguments  FFmpeg's arguments, unescaped, without the binary
     * @return string FFmpeg's console output
     *
     * @throws VideoProcessingException When the service is unreachable, refuses the request, or FFmpeg fails
     */
    public function run(array $arguments, string $step): string
    {
        $serviceUrl = config('media-processing.video_extraction.host_ffmpeg.url');

        if (! is_string($serviceUrl) || $serviceUrl === '') {
            throw new VideoProcessingException("FFmpeg {$step} needs the host encoder, but media-processing.video_extraction.host_ffmpeg.url is not set");
        }

        $url = rtrim($serviceUrl, '/').'/run';

        try {
            $response = Http::timeout((int) config('media-processing.video_extraction.host_ffmpeg.timeout_seconds', 3300))
                ->post($url, ['arguments' => $arguments]);
        } catch (ConnectionException $exception) {
            throw new VideoProcessingException("FFmpeg {$step} failed: host encoder unreachable at {$url}: {$exception->getMessage()}", previous: $exception);
        }

        if (! $response->successful() || ! is_int($response->json('exit_code')) || ! is_string($response->json('output'))) {
            $error = $response->json('error');

            throw new VideoProcessingException(sprintf('FFmpeg %s failed: host encoder refused the request (HTTP %d): %s', $step, $response->status(), is_string($error) ? $error : trim($response->body())));
        }

        if ($response->json('exit_code') !== 0) {
            throw new VideoProcessingException("FFmpeg {$step} failed: ".$response->json('output'));
        }

        return $response->json('output');
    }
}
