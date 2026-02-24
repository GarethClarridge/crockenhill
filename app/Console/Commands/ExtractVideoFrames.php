<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ExtractVideoFrames extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:extract-frames
                            {video-path : Path to video file (relative to storage)}
                            {--timestamps=* : Specific timestamps to extract (format: HH:MM:SS or seconds)}
                            {--interval= : Extract every N seconds}
                            {--output-dir=temp/frames : Output directory for frames}';

    /**
     * The console command description.
     */
    protected $description = 'Extract frames from video at specific timestamps for visual analysis debugging';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $videoPath = $this->argument('video-path');
        $timestamps = $this->option('timestamps');
        $interval = $this->option('interval');
        $outputDir = $this->option('output-dir');

        // Resolve video path
        $fullVideoPath = Storage::disk(config('media-processing.storage.temp_disk', 'local'))
            ->path($videoPath);

        if (! file_exists($fullVideoPath)) {
            $this->error("Video file not found: {$fullVideoPath}");

            return 1;
        }

        // Create output directory
        $fullOutputDir = Storage::disk('local')->path($outputDir);
        if (! is_dir($fullOutputDir)) {
            mkdir($fullOutputDir, 0755, true);
        }

        $this->info("Extracting frames from: {$videoPath}");
        $this->info("Output directory: {$outputDir}");

        // Extract at specific timestamps
        if (! empty($timestamps)) {
            $this->extractAtTimestamps($fullVideoPath, $timestamps, $fullOutputDir);
        }

        // Extract at intervals
        if ($interval) {
            $this->extractAtInterval($fullVideoPath, (int) $interval, $fullOutputDir);
        }

        // If neither specified, use defaults (song times from logs)
        if (empty($timestamps) && ! $interval) {
            $this->info('No timestamps or interval specified. Using default song/speech samples.');
            $defaultTimestamps = [
                '00:10:00', // Speech (before first song)
                '00:23:44', // Song 1 start
                '00:25:22', // Song 1 end
                '00:29:14', // Song 2 start
                '00:32:01', // Song 2 end
                '00:50:00', // Speech (middle of sermon)
            ];
            $this->extractAtTimestamps($fullVideoPath, $defaultTimestamps, $fullOutputDir);
        }

        $this->info('Frame extraction complete!');
        $this->info("Frames saved to: {$outputDir}");

        return 0;
    }

    /**
     * Extract frames at specific timestamps
     *
     * @param  array<int, string>  $timestamps
     */
    private function extractAtTimestamps(string $videoPath, array $timestamps, string $outputDir): void
    {
        $bar = $this->output->createProgressBar(count($timestamps));
        $bar->start();

        foreach ($timestamps as $timestamp) {
            $normalizedTime = $this->normalizeTimestamp($timestamp);
            $filename = sprintf('frame_%s.png', str_replace(':', '-', $normalizedTime));
            $outputPath = "{$outputDir}/{$filename}";

            $command = [
                config('media-processing.ffmpeg.ffmpeg_path'),
                '-ss', $normalizedTime,
                '-i', $videoPath,
                '-frames:v', '1',
                '-q:v', '2', // High quality
                $outputPath,
            ];

            $process = new Process($command);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                $this->newLine();
                $this->line("  ✓ Extracted frame at {$normalizedTime} -> {$filename}");
            } else {
                $this->newLine();
                $this->error("  ✗ Failed to extract frame at {$normalizedTime}");
                $this->error("    {$process->getErrorOutput()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Extract frames at regular intervals
     */
    private function extractAtInterval(string $videoPath, int $interval, string $outputDir): void
    {
        $this->info("Extracting frames every {$interval} seconds...");

        // Get video duration
        $durationCommand = [
            config('media-processing.ffmpeg.ffprobe_path'),
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ];

        $durationProcess = new Process($durationCommand);
        $durationProcess->run();

        if (! $durationProcess->isSuccessful()) {
            $this->error('Failed to get video duration');

            return;
        }

        $duration = (int) trim($durationProcess->getOutput());
        $timestamps = [];

        for ($time = 0; $time < $duration; $time += $interval) {
            $timestamps[] = gmdate('H:i:s', $time);
        }

        $this->info('Video duration: '.gmdate('H:i:s', $duration));
        $this->info('Will extract '.count($timestamps).' frames');

        $this->extractAtTimestamps($videoPath, $timestamps, $outputDir);
    }

    /**
     * Normalize timestamp to HH:MM:SS format
     */
    private function normalizeTimestamp(string $timestamp): string
    {
        // If already in HH:MM:SS format, return as-is
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $timestamp)) {
            return $timestamp;
        }

        // If in seconds, convert to HH:MM:SS
        if (is_numeric($timestamp)) {
            return gmdate('H:i:s', (int) $timestamp);
        }

        // If in MM:SS format, add hours
        if (preg_match('/^\d{2}:\d{2}$/', $timestamp)) {
            return "00:{$timestamp}";
        }

        return $timestamp;
    }
}
