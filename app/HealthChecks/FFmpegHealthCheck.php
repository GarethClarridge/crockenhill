<?php

namespace App\HealthChecks;

use Illuminate\Contracts\Support\Arrayable;

class FFmpegHealthCheck implements Arrayable
{
    /**
     * The name of the health check.
     */
    public function name(): string
    {
        return 'ffmpeg-availability';
    }

    public function run(): array
    {
        try {
            $ffmpegPath = config('livestream-processing.ffmpeg_path');
            $ffprobePath = config('livestream-processing.ffprobe_path');

            // Check if FFmpeg binary exists and is executable
            if (! file_exists($ffmpegPath) || ! is_executable($ffmpegPath)) {
                return [
                    'status' => 'error',
                    'message' => "FFmpeg binary not found or not executable at: {$ffmpegPath}",
                    'timestamp' => now()->toISOString(),
                ];
            }

            // Check if FFprobe binary exists and is executable
            if (! file_exists($ffprobePath) || ! is_executable($ffprobePath)) {
                return [
                    'status' => 'error',
                    'message' => "FFprobe binary not found or not executable at: {$ffprobePath}",
                    'timestamp' => now()->toISOString(),
                ];
            }

            // Test FFmpeg version command
            $output = shell_exec("{$ffmpegPath} -version 2>&1");
            if (! str_contains($output, 'ffmpeg version')) {
                return [
                    'status' => 'error',
                    'message' => 'FFmpeg version command failed',
                    'timestamp' => now()->toISOString(),
                ];
            }

            // Test FFprobe version command
            $output = shell_exec("{$ffprobePath} -version 2>&1");
            if (! str_contains($output, 'ffprobe version')) {
                return [
                    'status' => 'error',
                    'message' => 'FFprobe version command failed',
                    'timestamp' => now()->toISOString(),
                ];
            }

            return [
                'status' => 'healthy',
                'message' => 'FFmpeg and FFprobe are available and working',
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => "FFmpeg health check failed: {$e->getMessage()}",
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Convert the health check to an array.
     */
    public function toArray(): array
    {
        return $this->run();
    }
}
