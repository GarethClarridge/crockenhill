<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\VisualAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportVisualMetricsCommand extends Command
{
    protected $signature = 'media:export-visual-metrics
                            {video-path : Absolute path or path relative to the temp disk}
                            {--interval=10 : Sample every N seconds}
                            {--output= : Output CSV path (absolute or relative to the local disk)}';

    protected $description = 'Export sampled FFmpeg signalstats metrics for visual song detection calibration';

    public function handle(): int
    {
        $videoPath = $this->argument('video-path');
        $interval = (int) $this->option('interval');
        $outputOption = $this->option('output');

        if ($videoPath === '') {
            $this->error('A video path is required.');

            return self::FAILURE;
        }

        if ($interval <= 0) {
            $this->error('The interval must be a positive integer.');

            return self::FAILURE;
        }

        $resolvedVideoPath = $this->resolveVideoPath($videoPath);
        if ($resolvedVideoPath === null) {
            $this->error("Video file not found: {$videoPath}");

            return self::FAILURE;
        }

        $resolvedOutputPath = $this->resolveOutputPath(
            is_string($outputOption) && $outputOption !== '' ? $outputOption : $this->defaultOutputPath($resolvedVideoPath)
        );

        $directory = dirname($resolvedOutputPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->info("Sampling {$resolvedVideoPath} every {$interval} seconds...");

        $metrics = app(VisualAnalysisService::class)->extractFrameMetrics($resolvedVideoPath, $interval);

        $handle = fopen($resolvedOutputPath, 'wb');
        if ($handle === false) {
            $this->error("Unable to open output file: {$resolvedOutputPath}");

            return self::FAILURE;
        }

        fputcsv($handle, ['timestamp', 'brightness', 'contrast', 'edge_density', 'ylow', 'percentile_span']);

        foreach ($metrics as $metric) {
            fputcsv($handle, [
                $this->formatMetric($metric['timestamp']),
                $this->formatMetric($metric['brightness']),
                $this->formatMetric($metric['contrast']),
                $this->formatMetric($metric['edge_density']),
                $this->formatMetric($metric['ylow']),
                $this->formatMetric($metric['percentile_span']),
            ]);
        }

        fclose($handle);

        $this->info('Exported '.count($metrics)." samples to {$resolvedOutputPath}");

        return self::SUCCESS;
    }

    private function resolveVideoPath(string $videoPath): ?string
    {
        if (file_exists($videoPath)) {
            return $videoPath;
        }

        $storagePath = Storage::disk((string) config('media-processing.storage.temp_disk', 'local'))->path($videoPath);

        if (file_exists($storagePath)) {
            return $storagePath;
        }

        return null;
    }

    private function resolveOutputPath(string $outputPath): string
    {
        if (Str::startsWith($outputPath, DIRECTORY_SEPARATOR)) {
            return $outputPath;
        }

        return Storage::disk('local')->path($outputPath);
    }

    private function defaultOutputPath(string $videoPath): string
    {
        $filename = pathinfo($videoPath, PATHINFO_FILENAME);
        $slug = Str::slug($filename !== '' ? $filename : 'video');

        return sprintf('temp/visual-metrics/%s-%s.csv', $slug, now()->format('Ymd_His'));
    }

    private function formatMetric(float $value): string
    {
        return number_format($value, 6, '.', '');
    }
}
