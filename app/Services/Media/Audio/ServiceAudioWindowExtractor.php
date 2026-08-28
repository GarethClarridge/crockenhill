<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use RuntimeException;
use Symfony\Component\Process\Process;

class ServiceAudioWindowExtractor
{
    public function extract(string $sourcePath, float $start, float $end, string $processingId): string
    {
        $directory = storage_path('app/temp/service-transcription-recovery');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create service transcript recovery directory.');
        }

        $outputPath = $directory.'/'.preg_replace('/[^A-Za-z0-9_-]/', '-', $processingId).'-'.bin2hex(random_bytes(8)).'.mp3';
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', (string) $start,
            '-i', $sourcePath,
            '-t', (string) max(0.0, $end - $start),
            '-vn', '-ac', '1', '-ar', '16000', '-b:a', '64k',
            $outputPath,
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            throw new RuntimeException('Unable to extract pathological transcript audio window: '.$process->getErrorOutput());
        }

        return $outputPath;
    }

    public function delete(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
