<?php

namespace App\Services;

use App\Enums\LivestreamSegmentClassification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class VisualAnalysisService
{
    private int $sampleInterval;

    private float $brightnessThreshold;

    private float $contrastThreshold;

    private float $edgeDensityThreshold;

    private float $minConfidence;

    private string $tempDisk;

    public function __construct()
    {
        $config = config('media-processing.visual_analysis', []);

        $this->sampleInterval = $config['sample_interval_seconds'] ?? 10;
        $this->brightnessThreshold = $config['brightness_threshold'] ?? 0.6;
        $this->contrastThreshold = $config['contrast_threshold'] ?? 0.7;
        $this->edgeDensityThreshold = $config['edge_density_threshold'] ?? 0.5;
        $this->minConfidence = $config['min_confidence'] ?? 0.7;
        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
    }

    /**
     * Analyze video for song periods using visual frame analysis
     *
     * @param  ?\Closure(float $currentTime): void  $progressCallback
     * @return array<array{timestamp: float, classification: string, confidence: float, brightness: float, contrast: float, edge_density: float}>
     */
    public function analyzeVideo(string $videoPath, ?int $sampleInterval = null, ?\Closure $progressCallback = null): array
    {
        $interval = $sampleInterval ?? $this->sampleInterval;

        try {
            Log::info('Starting visual analysis', [
                'video_path' => $videoPath,
                'sample_interval' => $interval,
            ]);

            // Extract frame metrics using FFmpeg
            $metrics = $this->extractFrameMetrics($videoPath, $interval, $progressCallback);

            if (empty($metrics)) {
                throw new \Exception('No frame metrics extracted from video');
            }

            // Classify each frame
            $visualSamples = [];
            foreach ($metrics as $metric) {
                $classification = $this->classifyFrame($metric);
                // Merge classification results with metrics, excluding the nested metrics array
                $visualSamples[] = [
                    'timestamp' => $metric['timestamp'],
                    'classification' => $classification['classification'],
                    'confidence' => $classification['confidence'],
                    'brightness' => $metric['brightness'],
                    'contrast' => $metric['contrast'],
                    'edge_density' => $metric['edge_density'],
                ];
            }

            Log::info('Visual analysis complete', [
                'total_samples' => count($visualSamples),
                'song_samples' => count(array_filter($visualSamples, fn ($s) => $s['classification'] === LivestreamSegmentClassification::Song->value)),
                'speech_samples' => count(array_filter($visualSamples, fn ($s) => $s['classification'] === LivestreamSegmentClassification::Speech->value)),
            ]);

            return $visualSamples;

        } catch (\Exception $e) {
            Log::error('Visual analysis failed', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath,
            ]);

            throw $e;
        }
    }

    /**
     * Refine cluster boundaries with dense sampling
     *
     * @param  array{
     *     start_estimate: float,
     *     end_estimate: float,
     *     samples: list<float>,
     *     confidence?: float,
     *     sample_count?: int,
     *     refined_visual_start?: float,
     *     refined_visual_end?: float,
     *     dense_sample_count?: int
     * }  $cluster
     * @return array{refined_visual_start: float, refined_visual_end: float, dense_sample_count: int}
     */
    public function refineBoundaries(string $videoPath, array $cluster): array
    {
        $config = config('media-processing.visual_analysis', []);
        $denseInterval = $config['dense_sample_interval'] ?? 1;
        $introBuffer = $config['refinement_intro_buffer'] ?? 120;
        $outroBuffer = $config['refinement_outro_buffer'] ?? 60;

        // Define refinement region
        $searchStart = max(0, $cluster['start_estimate'] - $introBuffer);
        $searchEnd = $cluster['end_estimate'] + $outroBuffer;

        try {
            Log::info('Refining cluster boundaries with dense sampling', [
                'cluster_start' => gmdate('H:i:s', (int) $cluster['start_estimate']),
                'cluster_end' => gmdate('H:i:s', (int) $cluster['end_estimate']),
                'search_start' => gmdate('H:i:s', (int) $searchStart),
                'search_end' => gmdate('H:i:s', (int) $searchEnd),
                'interval' => $denseInterval,
            ]);

            // Extract dense samples in region
            $denseSamples = $this->extractFrameMetricsInRegion(
                $videoPath,
                $searchStart,
                $searchEnd,
                $denseInterval
            );

            if (empty($denseSamples)) {
                Log::warning('No dense samples extracted, using original cluster estimates');

                return [
                    'refined_visual_start' => $cluster['start_estimate'],
                    'refined_visual_end' => $cluster['end_estimate'],
                    'dense_sample_count' => 0,
                ];
            }

            // Classify each sample
            $songSamples = [];
            foreach ($denseSamples as $metric) {
                $classification = $this->classifyFrame($metric);
                if ($classification['classification'] === LivestreamSegmentClassification::Song->value) {
                    $songSamples[] = $metric['timestamp'];
                }
            }

            if (empty($songSamples)) {
                Log::warning('No song samples found in dense refinement, using original estimates');

                return [
                    'refined_visual_start' => $cluster['start_estimate'],
                    'refined_visual_end' => $cluster['end_estimate'],
                    'dense_sample_count' => count($denseSamples),
                ];
            }

            // Find earliest and latest song timestamps
            $refinedStart = min($songSamples);
            $refinedEnd = max($songSamples);

            Log::info('Boundaries refined', [
                'original_start' => gmdate('H:i:s', (int) $cluster['start_estimate']),
                'refined_start' => gmdate('H:i:s', (int) $refinedStart),
                'original_end' => gmdate('H:i:s', (int) $cluster['end_estimate']),
                'refined_end' => gmdate('H:i:s', (int) $refinedEnd),
                'song_samples' => count($songSamples),
            ]);

            return [
                'refined_visual_start' => $refinedStart,
                'refined_visual_end' => $refinedEnd,
                'dense_sample_count' => count($denseSamples),
            ];

        } catch (\Exception $e) {
            Log::error('Boundary refinement failed', [
                'error' => $e->getMessage(),
                'cluster' => $cluster,
            ]);

            // Fallback to original estimates
            return [
                'refined_visual_start' => $cluster['start_estimate'],
                'refined_visual_end' => $cluster['end_estimate'],
                'dense_sample_count' => 0,
            ];
        }
    }

    /**
     * Extract frame metrics for a specific time region
     *
     * @return array<array{timestamp: float, brightness: float, contrast: float, edge_density: float}>
     */
    public function extractFrameMetricsInRegion(
        string $videoPath,
        float $startTime,
        float $endTime,
        int $sampleInterval
    ): array {
        $metricsLogPath = 'temp/visual_metrics_region_'.Str::uuid().'.log';
        $fullMetricsLogPath = Storage::disk($this->tempDisk)->path($metricsLogPath);

        // Ensure directory exists
        $directory = dirname($fullMetricsLogPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        touch($fullMetricsLogPath);
        chmod($fullMetricsLogPath, 0644);

        try {
            $duration = $endTime - $startTime;

            // Use -ss before -i for fast keyframe seek, then -t to limit duration.
            // fps=1/N as a video filter is much faster than the select filter approach
            // because FFmpeg can seek between frames rather than decoding all of them.
            $command = [
                config('media-processing.ffmpeg.ffmpeg_path'),
                '-ss', (string) $startTime, // Fast seek before input (keyframe accurate)
                '-t', (string) $duration,   // Duration of region
                '-skip_frame', 'noref',
                '-threads', 'auto',
                '-i', $videoPath,
                '-vf', "fps=1/{$sampleInterval},signalstats,metadata=mode=print:file={$fullMetricsLogPath}",
                '-vsync', 'vfr',
                '-f', 'null',
                '-',
            ];

            $process = new Process($command);
            $process->setTimeout(3600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \Exception('FFmpeg frame extraction failed: '.$process->getErrorOutput());
            }

            if (! file_exists($fullMetricsLogPath) || filesize($fullMetricsLogPath) === 0) {
                throw new \Exception('Frame metrics log is empty or does not exist');
            }

            $metrics = $this->parseMetricsLog($fullMetricsLogPath);

            // Adjust timestamps to be relative to start of video (not start of region)
            foreach ($metrics as &$metric) {
                $metric['timestamp'] += $startTime;
            }

            Log::info('Region frame metrics extracted', [
                'region_start' => gmdate('H:i:s', (int) $startTime),
                'region_end' => gmdate('H:i:s', (int) $endTime),
                'sample_count' => count($metrics),
            ]);

            return $metrics;

        } catch (\Exception $e) {
            Log::error('Region frame metrics extraction failed', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            throw $e;
        } finally {
            // Cleanup metrics log
            if (file_exists($fullMetricsLogPath)) {
                @unlink($fullMetricsLogPath);
            }
        }
    }

    /**
     * Extract frame metrics using FFmpeg signalstats filter
     *
     * @param  ?\Closure(float $currentTime): void  $progressCallback
     * @return array<array{timestamp: float, brightness: float, contrast: float, edge_density: float}>
     */
    public function extractFrameMetrics(string $videoPath, int $sampleInterval, ?\Closure $progressCallback = null): array
    {
        $metricsLogPath = 'temp/visual_metrics_'.Str::uuid().'.log';
        $fullMetricsLogPath = Storage::disk($this->tempDisk)->path($metricsLogPath);

        // Ensure directory exists
        $directory = dirname($fullMetricsLogPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        touch($fullMetricsLogPath);
        chmod($fullMetricsLogPath, 0644);

        try {
            // Use -r 1/N as an input option so FFmpeg seeks between keyframes
            // rather than decoding every frame. This is dramatically faster than
            // the select filter which requires decoding all frames to count them.
            $command = [
                config('media-processing.ffmpeg.ffmpeg_path'),
                '-progress', 'pipe:1',
                '-skip_frame', 'noref',   // Skip non-reference frames during decode
                '-threads', 'auto',
                '-i', $videoPath,
                '-vf', "fps=1/{$sampleInterval},signalstats,metadata=mode=print:file={$fullMetricsLogPath}",
                '-vsync', 'vfr',
                '-f', 'null',
                '-',
            ];

            $process = new Process($command);
            $process->setTimeout(3600); // 1 hour timeout

            $lastReportedTime = 0.0;
            $process->run(function (string $type, string $data) use ($progressCallback, &$lastReportedTime): void {
                if ($progressCallback === null) {
                    return;
                }

                // Parse FFmpeg progress output for time position
                if (preg_match('/out_time_us=(\d+)/', $data, $matches)) {
                    $currentTime = (float) $matches[1] / 1_000_000;

                    // Only report every 60 seconds of video time to avoid log spam
                    if ($currentTime - $lastReportedTime >= 60.0) {
                        $lastReportedTime = $currentTime;
                        $progressCallback($currentTime);
                    }
                }
            });

            if (! $process->isSuccessful()) {
                throw new \Exception('FFmpeg frame extraction failed: '.$process->getErrorOutput());
            }

            if (! file_exists($fullMetricsLogPath) || filesize($fullMetricsLogPath) === 0) {
                throw new \Exception('Frame metrics log is empty or does not exist');
            }

            $metrics = $this->parseMetricsLog($fullMetricsLogPath);

            Log::info('Frame metrics extracted', [
                'sample_count' => count($metrics),
                'metrics_log_path' => $metricsLogPath,
            ]);

            return $metrics;

        } catch (\Exception $e) {
            Log::error('Frame metrics extraction failed', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath,
            ]);

            throw $e;
        } finally {
            // Cleanup metrics log
            if (file_exists($fullMetricsLogPath)) {
                @unlink($fullMetricsLogPath);
            }
        }
    }

    /**
     * Parse FFmpeg metadata output to extract frame metrics
     *
     * @return array<array{timestamp: float, brightness: float, contrast: float, edge_density: float}>
     */
    private function parseMetricsLog(string $logPath): array
    {
        $content = file_get_contents($logPath);
        if ($content === false) {
            return [];
        }

        $lines = explode("\n", trim($content));

        $metrics = [];
        $currentMetric = null;
        $currentTime = 0.0;

        foreach ($lines as $line) {
            // Extract timestamp from frame lines (format: "frame:N pts:1234 pts_time:12.34")
            if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $timeMatches)) {
                $currentTime = (float) $timeMatches[1];

                // Initialize new metric entry
                if ($currentMetric !== null) {
                    $metrics[] = $currentMetric;
                }

                $currentMetric = [
                    'timestamp' => $currentTime,
                    'brightness' => 0.0,
                    'contrast' => 0.0,
                    'edge_density' => 0.0,
                ];

                continue;
            }

            // Parse signalstats metadata
            // YAVG (Y-plane average) for brightness: range 0-255, normalize to 0-1
            if (preg_match('/lavfi\.signalstats\.YAVG=(\d+(?:\.\d+)?)/', $line, $matches)) {
                if ($currentMetric !== null) {
                    $currentMetric['brightness'] = (float) $matches[1] / 255.0;
                }
            }

            // YDIF (Y-plane difference) for contrast approximation
            if (preg_match('/lavfi\.signalstats\.YDIF=(\d+(?:\.\d+)?)/', $line, $matches)) {
                if ($currentMetric !== null) {
                    $currentMetric['contrast'] = (float) $matches[1] / 255.0;
                }
            }

            // For edge detection, we'll use YHIGH/YLOW difference
            // YHIGH represents high pixel values, useful for detecting white regions
            if (preg_match('/lavfi\.signalstats\.YHIGH=(\d+(?:\.\d+)?)/', $line, $matches)) {
                if ($currentMetric !== null) {
                    $yhigh = (float) $matches[1] / 255.0;
                    // High YHIGH indicates many bright pixels (white lyric boxes)
                    $currentMetric['edge_density'] = $yhigh;
                }
            }
        }

        // Add final metric if exists
        if ($currentMetric !== null) {
            $metrics[] = $currentMetric;
        }

        return $metrics;
    }

    /**
     * Classify a frame as song or speech based on visual characteristics
     *
     * @param  array{timestamp: float, brightness: float, contrast: float, edge_density: float}  $metrics
     * @return array{classification: string, confidence: float}
     */
    public function classifyFrame(array $metrics): array
    {
        // Brightness score: white lyric boxes have high brightness
        // Normalize: 0 at threshold, 1.0 at max (1.0)
        $brightnessScore = 0.0;
        if ($metrics['brightness'] >= $this->brightnessThreshold) {
            $range = 1.0 - $this->brightnessThreshold;
            $brightnessScore = min(1.0, ($metrics['brightness'] - $this->brightnessThreshold) / $range);
        }

        // Contrast score: black text on white has high contrast
        $contrastScore = 0.0;
        if ($metrics['contrast'] >= $this->contrastThreshold) {
            $range = 1.0 - $this->contrastThreshold;
            $contrastScore = min(1.0, ($metrics['contrast'] - $this->contrastThreshold) / $range);
        }

        // Edge density score: lyric boxes have strong edges
        $edgeDensityScore = 0.0;
        if ($metrics['edge_density'] >= $this->edgeDensityThreshold) {
            $range = 1.0 - $this->edgeDensityThreshold;
            $edgeDensityScore = min(1.0, ($metrics['edge_density'] - $this->edgeDensityThreshold) / $range);
        }

        // Calculate overall confidence (weighted average)
        // Adjusted weights based on actual video analysis:
        // - Edge density (YHIGH) is the ONLY reliable indicator
        // - Brightness has some signal but not strong
        // - Contrast (YDIF) is useless for this video type
        $weights = [
            'brightness' => 0.2,   // Minor signal
            'contrast' => 0.0,     // Disabled - unreliable metric
            'edge_density' => 0.8, // Primary indicator: white lyric boxes = high YHIGH
        ];

        $confidence = ($brightnessScore * $weights['brightness']) +
                     ($contrastScore * $weights['contrast']) +
                     ($edgeDensityScore * $weights['edge_density']);

        // Classify based on confidence threshold
        $classification = $confidence >= $this->minConfidence ? LivestreamSegmentClassification::Song->value : LivestreamSegmentClassification::Speech->value;

        // Debug logging to understand classification decisions
        if ($metrics['timestamp'] % 600 === 0 || $confidence >= 0.5) {
            // Log every 10 minutes OR when confidence is relatively high
            Log::debug('Frame classification', [
                'timestamp' => gmdate('H:i:s', (int) $metrics['timestamp']),
                'raw_metrics' => [
                    'brightness' => round($metrics['brightness'], 3),
                    'contrast' => round($metrics['contrast'], 3),
                    'edge_density' => round($metrics['edge_density'], 3),
                ],
                'scores' => [
                    'brightness' => round($brightnessScore, 3),
                    'contrast' => round($contrastScore, 3),
                    'edge_density' => round($edgeDensityScore, 3),
                ],
                'confidence' => round($confidence, 3),
                'classification' => $classification,
                'thresholds' => [
                    'brightness' => $this->brightnessThreshold,
                    'contrast' => $this->contrastThreshold,
                    'edge_density' => $this->edgeDensityThreshold,
                    'min_confidence' => $this->minConfidence,
                ],
            ]);
        }

        return [
            'classification' => $classification,
            'confidence' => round($confidence, 3),
        ];
    }
}
