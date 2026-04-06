<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LivestreamSegmentClassification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class VisualAnalysisService
{
    public const RULE_SET_AUTO = 'auto';

    public const RULE_SET_OLD_STYLE = 'old_style';

    public const RULE_SET_NEW_STYLE = 'new_style';

    private const SAMPLE_INTERVAL_SECONDS = 10;

    private const BRIGHTNESS_THRESHOLD = 0.48;

    private const CONTRAST_THRESHOLD = 0.0;

    private const EDGE_DENSITY_THRESHOLD = 0.75;

    private const MIN_CONFIDENCE = 0.35;

    private const YLOW_CEILING_NEW = 0.20;

    private const BRIGHTNESS_CEILING_NEW = 0.50;

    private const SPAN_THRESHOLD_NEW = 0.35;

    private const LOW_PROFILE_YLOW_CEILING = 0.18;

    private const LOW_PROFILE_BRIGHTNESS_FLOOR = 0.24;

    private const LOW_PROFILE_BRIGHTNESS_CEILING = 0.34;

    private const LOW_PROFILE_EDGE_DENSITY_FLOOR = 0.48;

    private const LOW_PROFILE_SPAN_FLOOR = 0.31;

    private const LOW_PROFILE_CONTRAST_CEILING = 0.04;

    private const LOW_PROFILE_BASE_CONFIDENCE = 0.38;

    private const DENSE_CLUSTER_MAX_GAP_SECONDS = 30;

    private const DENSE_START_ANCHOR_LEAD_SECONDS = 15;

    private const DENSE_START_ANCHOR_TRAIL_SECONDS = 30;

    private const DENSE_END_ANCHOR_LEAD_SECONDS = 30;

    private const DENSE_END_ANCHOR_TRAIL_SECONDS = 30;

    private string $tempDisk;

    public function __construct()
    {
        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
    }

    /**
     * Analyze video for song periods using visual frame analysis
     *
     * @param  ?\Closure(float $currentTime): void  $progressCallback
     * @param  self::RULE_SET_*  $ruleSet
     * @return array<array{
     *     timestamp: float,
     *     classification: string,
     *     confidence: float,
     *     brightness: float,
     *     contrast: float,
     *     edge_density: float,
     *     ylow: float,
     *     percentile_span: float,
     *     detection_mode: 'old_style'|'new_style'|'none'
     * }>
     */
    public function analyzeVideo(
        string $videoPath,
        ?int $sampleInterval = null,
        ?\Closure $progressCallback = null,
        string $ruleSet = self::RULE_SET_AUTO
    ): array {
        $interval = $sampleInterval ?? self::SAMPLE_INTERVAL_SECONDS;
        $normalizedRuleSet = $this->normalizeRuleSet($ruleSet);

        try {
            Log::info('Starting visual analysis', [
                'video_path' => $videoPath,
                'sample_interval' => $interval,
                'rule_set' => $normalizedRuleSet,
            ]);

            // Extract frame metrics using FFmpeg
            $metrics = $this->extractFrameMetrics($videoPath, $interval, $progressCallback);

            if (empty($metrics)) {
                throw new \Exception('No frame metrics extracted from video');
            }

            // Classify each frame
            $visualSamples = [];
            foreach ($metrics as $metric) {
                $classification = $this->classifyFrame($metric, $normalizedRuleSet);
                // Merge classification results with metrics, excluding the nested metrics array
                $visualSamples[] = [
                    'timestamp' => $metric['timestamp'],
                    'classification' => $classification['classification'],
                    'confidence' => $classification['confidence'],
                    'brightness' => $metric['brightness'],
                    'contrast' => $metric['contrast'],
                    'edge_density' => $metric['edge_density'],
                    'ylow' => $metric['ylow'],
                    'percentile_span' => $metric['percentile_span'],
                    'detection_mode' => $classification['detection_mode'],
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
     * @param  self::RULE_SET_*  $ruleSet
     * @return array{refined_visual_start: float, refined_visual_end: float, dense_sample_count: int}
     */
    public function refineBoundaries(
        string $videoPath,
        array $cluster,
        string $ruleSet = self::RULE_SET_AUTO
    ): array {
        $denseInterval = 1;
        $introBuffer = 120;
        $outroBuffer = 60;
        $normalizedRuleSet = $this->normalizeRuleSet($ruleSet);

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
                'rule_set' => $normalizedRuleSet,
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
                $classification = $this->classifyFrame($metric, $normalizedRuleSet);
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

            $denseClusters = $this->clusterDenseSongSamples($songSamples);
            $bestCluster = $this->selectBestDenseCluster($denseClusters, $cluster);

            if ($bestCluster === null) {
                Log::warning('No anchored dense song cluster found, using original estimates');

                return [
                    'refined_visual_start' => $cluster['start_estimate'],
                    'refined_visual_end' => $cluster['end_estimate'],
                    'dense_sample_count' => count($denseSamples),
                ];
            }

            $refinedStart = $this->selectAnchoredDenseBoundary(
                $songSamples,
                (float) $cluster['start_estimate'],
                self::DENSE_START_ANCHOR_LEAD_SECONDS,
                self::DENSE_START_ANCHOR_TRAIL_SECONDS,
                true
            ) ?? $bestCluster['start'];
            $refinedEnd = $this->selectAnchoredDenseBoundary(
                $songSamples,
                (float) $cluster['end_estimate'],
                self::DENSE_END_ANCHOR_LEAD_SECONDS,
                self::DENSE_END_ANCHOR_TRAIL_SECONDS,
                false
            ) ?? $bestCluster['end'];

            Log::info('Boundaries refined', [
                'original_start' => gmdate('H:i:s', (int) $cluster['start_estimate']),
                'refined_start' => gmdate('H:i:s', (int) $refinedStart),
                'original_end' => gmdate('H:i:s', (int) $cluster['end_estimate']),
                'refined_end' => gmdate('H:i:s', (int) $refinedEnd),
                'song_samples' => count($songSamples),
                'selected_cluster_samples' => $bestCluster['sample_count'],
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
     * @return array<array{
     *     timestamp: float,
     *     brightness: float,
     *     contrast: float,
     *     edge_density: float,
     *     ylow: float,
     *     percentile_span: float
     * }>
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
     * @return array<array{
     *     timestamp: float,
     *     brightness: float,
     *     contrast: float,
     *     edge_density: float,
     *     ylow: float,
     *     percentile_span: float
     * }>
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
     * @return array<array{
     *     timestamp: float,
     *     brightness: float,
     *     contrast: float,
     *     edge_density: float,
     *     ylow: float,
     *     percentile_span: float
     * }>
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
                    $metrics[] = $this->finalizeMetric($currentMetric);
                }

                $currentMetric = [
                    'timestamp' => $currentTime,
                    'brightness' => 0.0,
                    'contrast' => 0.0,
                    'edge_density' => 0.0,
                    'ylow' => 0.0,
                    'percentile_span' => 0.0,
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
                    // YHIGH is the 90th percentile luminance value.
                    $currentMetric['edge_density'] = $yhigh;
                }
            }

            if (preg_match('/lavfi\.signalstats\.YLOW=(\d+(?:\.\d+)?)/', $line, $matches)) {
                if ($currentMetric !== null) {
                    $currentMetric['ylow'] = (float) $matches[1] / 255.0;
                }
            }
        }

        // Add final metric if exists
        if ($currentMetric !== null) {
            $metrics[] = $this->finalizeMetric($currentMetric);
        }

        return $metrics;
    }

    /**
     * @param  array{
     *     timestamp: float,
     *     brightness: float,
     *     contrast: float,
     *     edge_density: float,
     *     ylow: float,
     *     percentile_span: float
     * }  $metric
     * @return array{
     *     timestamp: float,
     *     brightness: float,
     *     contrast: float,
     *     edge_density: float,
     *     ylow: float,
     *     percentile_span: float
     * }
     */
    private function finalizeMetric(array $metric): array
    {
        $metric['percentile_span'] = max(0.0, $metric['edge_density'] - $metric['ylow']);

        return $metric;
    }

    /**
     * Classify a frame as song or speech based on visual characteristics
     *
     * @param  array{
     *     timestamp: float,
     *     brightness: float,
     *     contrast: float,
     *     edge_density: float,
     *     ylow?: float,
     *     percentile_span?: float
     * }  $metrics
     * @param  self::RULE_SET_*  $ruleSet
     * @return array{classification: string, confidence: float, detection_mode: 'old_style'|'new_style'|'none'}
     */
    public function classifyFrame(array $metrics, string $ruleSet = self::RULE_SET_AUTO): array
    {
        $ylow = (float) ($metrics['ylow'] ?? 0.0);
        $percentileSpan = (float) ($metrics['percentile_span'] ?? max(0.0, $metrics['edge_density'] - $ylow));
        $normalizedRuleSet = $this->normalizeRuleSet($ruleSet);

        // Brightness score: white lyric boxes have high brightness
        // Normalize: 0 at threshold, 1.0 at max (1.0)
        $brightnessScore = 0.0;
        if ($metrics['brightness'] >= self::BRIGHTNESS_THRESHOLD) {
            $range = 1.0 - self::BRIGHTNESS_THRESHOLD;
            $brightnessScore = min(1.0, ($metrics['brightness'] - self::BRIGHTNESS_THRESHOLD) / $range);
        }

        // Contrast score: black text on white has high contrast
        $contrastScore = 0.0;
        if ($metrics['contrast'] >= self::CONTRAST_THRESHOLD) {
            $range = 1.0 - self::CONTRAST_THRESHOLD;
            $contrastScore = min(1.0, ($metrics['contrast'] - self::CONTRAST_THRESHOLD) / $range);
        }

        // Edge density score: lyric boxes have strong edges
        $edgeDensityScore = 0.0;
        if ($metrics['edge_density'] >= self::EDGE_DENSITY_THRESHOLD) {
            $range = 1.0 - self::EDGE_DENSITY_THRESHOLD;
            $edgeDensityScore = min(1.0, ($metrics['edge_density'] - self::EDGE_DENSITY_THRESHOLD) / $range);
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

        $modeAConfidence = $confidence;
        $isOldStyleSong = $modeAConfidence >= self::MIN_CONFIDENCE;

        $isNewStyleSong = $ylow <= self::YLOW_CEILING_NEW
            && $metrics['brightness'] <= self::BRIGHTNESS_CEILING_NEW
            && $percentileSpan >= self::SPAN_THRESHOLD_NEW;

        $modeBConfidence = 0.0;
        if ($isNewStyleSong) {
            $spanRange = 1.0 - self::SPAN_THRESHOLD_NEW;
            $modeBConfidence = min(1.0, ($percentileSpan - self::SPAN_THRESHOLD_NEW) / $spanRange);
        }

        $modeCLowProfileConfidence = 0.0;
        $isLowProfileSong = $ylow <= self::LOW_PROFILE_YLOW_CEILING
            && $metrics['brightness'] >= self::LOW_PROFILE_BRIGHTNESS_FLOOR
            && $metrics['brightness'] <= self::LOW_PROFILE_BRIGHTNESS_CEILING
            && $metrics['edge_density'] >= self::LOW_PROFILE_EDGE_DENSITY_FLOOR
            && $percentileSpan >= self::LOW_PROFILE_SPAN_FLOOR
            && $metrics['contrast'] <= self::LOW_PROFILE_CONTRAST_CEILING;

        if ($isLowProfileSong) {
            $edgeBoost = min(0.12, max(0.0, ($metrics['edge_density'] - self::LOW_PROFILE_EDGE_DENSITY_FLOOR) * 0.4));
            $spanBoost = min(0.08, max(0.0, ($percentileSpan - self::LOW_PROFILE_SPAN_FLOOR) * 0.3));
            $modeCLowProfileConfidence = min(0.65, self::LOW_PROFILE_BASE_CONFIDENCE + $edgeBoost + $spanBoost);
        }

        $modeBConfidence = max($modeBConfidence, $modeCLowProfileConfidence);
        $confidence = match ($normalizedRuleSet) {
            self::RULE_SET_OLD_STYLE => $modeAConfidence,
            self::RULE_SET_NEW_STYLE => $modeBConfidence,
            default => max($modeAConfidence, $modeBConfidence),
        };
        $classification = $confidence >= self::MIN_CONFIDENCE
            ? LivestreamSegmentClassification::Song->value
            : LivestreamSegmentClassification::Speech->value;

        $detectionMode = 'none';
        if ($classification === LivestreamSegmentClassification::Song->value) {
            $detectionMode = match ($normalizedRuleSet) {
                self::RULE_SET_OLD_STYLE => self::RULE_SET_OLD_STYLE,
                self::RULE_SET_NEW_STYLE => self::RULE_SET_NEW_STYLE,
                default => $modeAConfidence >= $modeBConfidence ? self::RULE_SET_OLD_STYLE : self::RULE_SET_NEW_STYLE,
            };
        }

        // Debug logging to understand classification decisions
        if ($metrics['timestamp'] % 600 === 0 || $confidence >= 0.5) {
            // Log every 10 minutes OR when confidence is relatively high
            Log::debug('Frame classification', [
                'timestamp' => gmdate('H:i:s', (int) $metrics['timestamp']),
                'raw_metrics' => [
                    'brightness' => round($metrics['brightness'], 3),
                    'contrast' => round($metrics['contrast'], 3),
                    'edge_density' => round($metrics['edge_density'], 3),
                    'ylow' => round($ylow, 3),
                    'percentile_span' => round($percentileSpan, 3),
                ],
                'scores' => [
                    'brightness' => round($brightnessScore, 3),
                    'contrast' => round($contrastScore, 3),
                    'edge_density' => round($edgeDensityScore, 3),
                ],
                'mode_confidences' => [
                    'old_style' => round($modeAConfidence, 3),
                    'new_style' => round($modeBConfidence, 3),
                    'low_profile' => round($modeCLowProfileConfidence, 3),
                ],
                'confidence' => round($confidence, 3),
                'classification' => $classification,
                'detection_mode' => $detectionMode,
                'rule_set' => $normalizedRuleSet,
                'thresholds' => [
                    'brightness' => self::BRIGHTNESS_THRESHOLD,
                    'contrast' => self::CONTRAST_THRESHOLD,
                    'edge_density' => self::EDGE_DENSITY_THRESHOLD,
                    'min_confidence' => self::MIN_CONFIDENCE,
                    'ylow_ceiling_new' => self::YLOW_CEILING_NEW,
                    'brightness_ceiling_new' => self::BRIGHTNESS_CEILING_NEW,
                    'span_threshold_new' => self::SPAN_THRESHOLD_NEW,
                    'low_profile_ylow_ceiling' => self::LOW_PROFILE_YLOW_CEILING,
                    'low_profile_brightness_floor' => self::LOW_PROFILE_BRIGHTNESS_FLOOR,
                    'low_profile_brightness_ceiling' => self::LOW_PROFILE_BRIGHTNESS_CEILING,
                    'low_profile_edge_floor' => self::LOW_PROFILE_EDGE_DENSITY_FLOOR,
                    'low_profile_span_floor' => self::LOW_PROFILE_SPAN_FLOOR,
                    'low_profile_contrast_ceiling' => self::LOW_PROFILE_CONTRAST_CEILING,
                ],
            ]);
        }

        return [
            'classification' => $classification,
            'confidence' => round($confidence, 3),
            'detection_mode' => $detectionMode,
        ];
    }

    /**
     * @return self::RULE_SET_*
     */
    private function normalizeRuleSet(string $ruleSet): string
    {
        return match ($ruleSet) {
            self::RULE_SET_OLD_STYLE, self::RULE_SET_NEW_STYLE => $ruleSet,
            default => self::RULE_SET_AUTO,
        };
    }

    /**
     * @param  list<float>  $timestamps
     * @return list<array{start: float, end: float, sample_count: int}>
     */
    private function clusterDenseSongSamples(array $timestamps): array
    {
        if ($timestamps === []) {
            return [];
        }

        sort($timestamps);

        $clusters = [];
        $currentStart = $timestamps[0];
        $currentEnd = $timestamps[0];
        $sampleCount = 1;

        foreach (array_slice($timestamps, 1) as $timestamp) {
            if (($timestamp - $currentEnd) <= self::DENSE_CLUSTER_MAX_GAP_SECONDS) {
                $currentEnd = $timestamp;
                $sampleCount++;

                continue;
            }

            $clusters[] = [
                'start' => $currentStart,
                'end' => $currentEnd,
                'sample_count' => $sampleCount,
            ];

            $currentStart = $timestamp;
            $currentEnd = $timestamp;
            $sampleCount = 1;
        }

        $clusters[] = [
            'start' => $currentStart,
            'end' => $currentEnd,
            'sample_count' => $sampleCount,
        ];

        return $clusters;
    }

    /**
     * @param  list<array{start: float, end: float, sample_count: int}>  $denseClusters
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
     * @return array{start: float, end: float, sample_count: int}|null
     */
    private function selectBestDenseCluster(array $denseClusters, array $cluster): ?array
    {
        if ($denseClusters === []) {
            return null;
        }

        $startEstimate = (float) $cluster['start_estimate'];
        $endEstimate = (float) $cluster['end_estimate'];
        $estimateMidpoint = $startEstimate + (($endEstimate - $startEstimate) / 2.0);
        $coarseSamples = $cluster['samples'];

        usort($denseClusters, function (array $left, array $right) use ($coarseSamples, $startEstimate, $endEstimate, $estimateMidpoint): int {
            $leftAnchors = $this->countAnchoredSamples($left, $coarseSamples);
            $rightAnchors = $this->countAnchoredSamples($right, $coarseSamples);

            if ($leftAnchors !== $rightAnchors) {
                return $rightAnchors <=> $leftAnchors;
            }

            $leftOverlap = $this->calculateWindowOverlap($left['start'], $left['end'], $startEstimate, $endEstimate);
            $rightOverlap = $this->calculateWindowOverlap($right['start'], $right['end'], $startEstimate, $endEstimate);

            if ($leftOverlap !== $rightOverlap) {
                return $rightOverlap <=> $leftOverlap;
            }

            if ($left['sample_count'] !== $right['sample_count']) {
                return $right['sample_count'] <=> $left['sample_count'];
            }

            $leftDistance = abs((($left['start'] + $left['end']) / 2.0) - $estimateMidpoint);
            $rightDistance = abs((($right['start'] + $right['end']) / 2.0) - $estimateMidpoint);

            return $leftDistance <=> $rightDistance;
        });

        return $denseClusters[0];
    }

    /**
     * @param  array{start: float, end: float, sample_count: int}  $denseCluster
     * @param  list<float>  $coarseSamples
     */
    private function countAnchoredSamples(array $denseCluster, array $coarseSamples): int
    {
        return count(array_filter($coarseSamples, fn (float $sample): bool => $sample >= $denseCluster['start'] && $sample <= $denseCluster['end']));
    }

    private function calculateWindowOverlap(float $leftStart, float $leftEnd, float $rightStart, float $rightEnd): float
    {
        $overlapStart = max($leftStart, $rightStart);
        $overlapEnd = min($leftEnd, $rightEnd);

        return max(0.0, $overlapEnd - $overlapStart);
    }

    /**
     * @param  list<float>  $songSamples
     */
    private function selectAnchoredDenseBoundary(
        array $songSamples,
        float $estimate,
        float $leadWindow,
        float $trailWindow,
        bool $takeMinimum
    ): ?float {
        $candidates = array_values(array_filter(
            $songSamples,
            fn (float $timestamp): bool => $timestamp >= ($estimate - $leadWindow) && $timestamp <= ($estimate + $trailWindow)
        ));

        if ($candidates === []) {
            return null;
        }

        return $takeMinimum ? min($candidates) : max($candidates);
    }
}
