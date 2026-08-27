<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use RuntimeException;

class HistoricVideoReencodeConcatenator
{
    /** @param list<string> $files */
    public function concatenate(array $files, string $outputPath): void
    {
        if (count($files) < 2) {
            throw new RuntimeException('Re-encode concatenation requires at least two input files.');
        }

        $inputs = implode(' ', array_map(fn (string $file): string => '-i '.escapeshellarg($file), $files));
        $filterInputs = implode('', array_map(fn (int $index): string => "[{$index}:v][{$index}:a]", array_keys($files)));
        $filter = "{$filterInputs}concat=n=".count($files).':v=1:a=1[outv][outa]';
        $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        $command = escapeshellarg($ffmpegPath)
            ." {$inputs}"
            .' -filter_complex '.escapeshellarg($filter)
            .' -map [outv] -map [outa]'
            .' -c:v libx264 -preset veryfast -c:a aac -b:a 192k'
            .' '.escapeshellarg($outputPath)
            .' 2>&1';

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('FFmpeg re-encode concat failed: '.implode(' ', array_slice($output, -3)));
        }
    }
}
