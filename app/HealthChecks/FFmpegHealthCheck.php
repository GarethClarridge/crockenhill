<?php

namespace App\HealthChecks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class FFmpegHealthCheck extends Check
{
    public function run(): Result
    {
        $result = Result::make();

        try {
            $ffmpegPath = config('livestream-processing.ffmpeg_path');
            $ffprobePath = config('livestream-processing.ffprobe_path');

            // Check if FFmpeg binary exists and is executable
            if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                return $result->failed("FFmpeg binary not found or not executable at: {$ffmpegPath}");
            }

            // Check if FFprobe binary exists and is executable
            if (!file_exists($ffprobePath) || !is_executable($ffprobePath)) {
                return $result->failed("FFprobe binary not found or not executable at: {$ffprobePath}");
            }

            // Test FFmpeg version command
            $output = shell_exec("{$ffmpegPath} -version 2>&1");
            if (!str_contains($output, 'ffmpeg version')) {
                return $result->failed('FFmpeg version command failed');
            }

            // Test FFprobe version command
            $output = shell_exec("{$ffprobePath} -version 2>&1");
            if (!str_contains($output, 'ffprobe version')) {
                return $result->failed('FFprobe version command failed');
            }

            return $result->ok('FFmpeg and FFprobe are available and working');

        } catch (\Exception $e) {
            return $result->failed("FFmpeg health check failed: {$e->getMessage()}");
        }
    }
}