<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\LivestreamSegment;
use App\Enums\LivestreamSegmentClassification;
use App\Exceptions\SegmentationException;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Service for analyzing video files and segmenting them into meaningful components.
 *
 * Utilizes FFmpeg/FFprobe to extract technical metadata and generate RMS (loudness) logs.
 * These logs are then analyzed to differentiate between speech and song segments,
 * identifying the primary sermon candidate based on duration and characteristics.
 */
class VideoSegmentationService
{
    /** Region in seconds to sample adjacent speech for per-song RMS threshold calibration. */
    private const CALIBRATION_SPEECH_BUFFER = 60.0;

    /** Minimum allowed RMS threshold (dB) to avoid near-silent noise floor interference. */
    private const THRESHOLD_SAFETY_FLOOR = -80.0;

    /** Maximum allowed RMS threshold (dB) to prevent bias toward extreme peaks. */
    private const THRESHOLD_SAFETY_CEILING = -20.0;

    /** Time window (seconds) to search for song start boundaries before a visual estimate. */
    private const INTRO_SEARCH_BUFFER = 120.0;

    /** Time window (seconds) to search for song end boundaries after a visual estimate. */
    private const OUTRO_SEARCH_BUFFER = 60.0;

    /** Maximum gap (seconds) between loud sections allowed before they are considered distinct events. */
    private const RMS_SECTION_MERGE_GAP = 45.0;

    /** Hard limit on how far ahead of a visual detection point we will search for an audio start. */
    private const VISUAL_START_LEAD_CAP_SECONDS = 10.0;

    private ?FFProbe $ffprobe = null;

    private readonly float $minSermonDuration;

    private readonly string $tempDisk;

    public function __construct(
        private readonly RmsAnalysisService $rmsAnalysisService,
        private readonly StorageAdapterHelper $storageAdapter,
    ) {
        // Skip FFProbe initialization in testing environment to prevent hangs
        if (! app()->environment('testing')) {
            $this->ffprobe = FFProbe::create([
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);
        }

        $this->minSermonDuration = config()->float('media-processing.segmentation.min_sermon_duration', 300.0);
        $this->tempDisk = config()->string('media-processing.storage.temp_disk', 'local');
    }

    /**
     * Generate an RMS loudness log for a video file using FFmpeg.
     *
     * Extracts precise loudness data at 8kHz for performance, producing a log
     * that maps PTS timestamps to RMS levels.
     *
     * @param  string  $videoPath  Absolute path to the source video file
     * @return string  Relative path to the generated RMS log on the temporary disk
     *
     * @throws ProcessFailedException If the FFmpeg process fails
     * @throws SegmentationException If the log file is not created or is empty
     * @throws \Exception For underlying storage or permission failures
     */
    public function generateRmsLog(string $videoPath): string
    {
        $rmsLogPath = 'temp/rms_'.Str::uuid().'.log';
        $fullRmsLogPath = Storage::disk($this->tempDisk)->path($rmsLogPath);

        // Ensure the directory exists and is writable
        $directory = dirname($fullRmsLogPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Create empty file first to ensure FFmpeg can write to it
        touch($fullRmsLogPath);
        chmod($fullRmsLogPath, 0644);

        try {
            // Optimized command with 8kHz downsampling for faster RMS analysis
            $command = [
                config('media-processing.ffmpeg.ffmpeg_path'),
                '-threads', 'auto',
                '-probesize', '32M',
                '-analyzeduration', '10M',
                '-i', $videoPath,
                '-af', "aresample=8000,astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file={$fullRmsLogPath}",
                '-f', 'null',
                '-',
            ];

            $process = new Process($command);
            $process->setTimeout(7200); // 2 hour timeout for large files
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            if (! $this->storageAdapter->fileExists($rmsLogPath, $this->tempDisk) || $this->storageAdapter->fileSize($rmsLogPath, $this->tempDisk) === 0) {
                throw new SegmentationException('Failed to generate RMS log file or file is empty');
            }

            Log::info('RMS log generated successfully', ['path' => $rmsLogPath, 'size' => $this->storageAdapter->fileSize($rmsLogPath, $this->tempDisk)]);

            return $rmsLogPath;

        } catch (\Exception $e) {
            Log::error('Failed to generate RMS log', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath,
                'rms_log_path' => $rmsLogPath,
            ]);

            throw $e;
        }
    }

    /**
     * Analyze an RMS log to identify speech and song segments.
     *
     * Determines an adaptive or fixed threshold to partition the audio into
     * high-signal (song) and low-signal (speech) blocks.
     *
     * @param  string  $rmsLogPath  Relative path to the RMS log file
     * @return array{
     *     segments: list<LivestreamSegment>,
     *     threshold_metadata: array{
     *         threshold: float,
     *         method: 'fixed'|'adaptive'|'fallback',
     *         log_data: array<string, mixed>,
     *         rms_stats?: array<string, mixed>
     *     }
     * }
     *
     * @throws SegmentationException If the RMS log is missing or invalid
     */
    public function analyzeSegments(string $rmsLogPath): array
    {
        try {
            if (! $this->storageAdapter->fileExists($rmsLogPath, $this->tempDisk)) {
                throw new SegmentationException('RMS log file not found: '.$rmsLogPath);
            }

            $logContent = $this->storageAdapter->getFileContents($this->tempDisk, $rmsLogPath);

            $thresholdResult = $this->rmsAnalysisService->determineThreshold($logContent);

            Log::info('Threshold determination result', $thresholdResult['log_data']);

            $segments = $this->parseRmsLog($logContent, $thresholdResult['threshold']);

            Log::info('Segments analyzed', [
                'total_segments' => count($segments),
                'speech_segments' => count(array_filter($segments, fn ($s) => $s->isSpeech())),
                'song_segments' => count(array_filter($segments, fn ($s) => $s->isSong())),
                'threshold_used' => $thresholdResult['threshold'],
                'method_used' => $thresholdResult['method'],
            ]);

            return [
                'segments' => $segments,
                'threshold_metadata' => $thresholdResult,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to analyze segments', [
                'error' => $e->getMessage(),
                'rms_log_path' => $rmsLogPath,
            ]);

            throw $e;
        }
    }

    /**
     * @return list<LivestreamSegment>
     */
    private function parseRmsLog(string $logContent, ?float $adaptiveThreshold = null): array
    {
        $lines = explode("\n", trim($logContent));
        $threshold = $adaptiveThreshold ?? $this->rmsAnalysisService->getRmsThreshold();
        $loudSections = $this->rmsAnalysisService->parseAudioSections($logContent, $threshold);

        $totalDuration = $this->rmsAnalysisService->getTotalDuration($logContent, $lines);

        $rmsData = $this->rmsAnalysisService->extractRmsData($logContent);

        $segments = $this->combineLoudAndQuietSections($loudSections, $totalDuration, $rmsData);

        return $this->identifySermonCandidate($segments);
    }

    /**
     * @param  array<int, array{start: float, end: float}>  $loudSections
     * @param  array<int, array{time: float, rms: float}>  $rmsData
     * @return list<LivestreamSegment>
     */
    private function combineLoudAndQuietSections(array $loudSections, float $totalDuration, array $rmsData): array
    {
        $combinedSections = [];
        $previousEnd = 0.0;
        $segmentOrder = 0;

        foreach ($loudSections as $section) {
            $start = $section['start'];
            $end = $section['end'];

            if ($start > $previousEnd) {
                $speechRms = $this->rmsAnalysisService->calculateSegmentRms($previousEnd, $start, $rmsData);
                $combinedSections[] = new LivestreamSegment(
                    startTime: $previousEnd,
                    endTime: $start,
                    duration: $start - $previousEnd,
                    classification: LivestreamSegmentClassification::Speech->value,
                    avgRms: $speechRms['avg'],
                    peakRms: $speechRms['peak'],
                    segmentOrder: $segmentOrder++
                );
            }

            $songRms = $this->rmsAnalysisService->calculateSegmentRms($start, $end, $rmsData);
            $combinedSections[] = new LivestreamSegment(
                startTime: $start,
                endTime: $end,
                duration: $end - $start,
                classification: LivestreamSegmentClassification::Song->value,
                avgRms: $songRms['avg'],
                peakRms: $songRms['peak'],
                segmentOrder: $segmentOrder++
            );

            $previousEnd = $end;
        }

        if ($previousEnd < $totalDuration) {
            $speechRms = $this->rmsAnalysisService->calculateSegmentRms($previousEnd, $totalDuration, $rmsData);
            $combinedSections[] = new LivestreamSegment(
                startTime: $previousEnd,
                endTime: $totalDuration,
                duration: $totalDuration - $previousEnd,
                classification: LivestreamSegmentClassification::Speech->value,
                avgRms: $speechRms['avg'],
                peakRms: $speechRms['peak'],
                segmentOrder: $segmentOrder
            );
        }

        return $combinedSections;
    }

    /**
     * @param  list<LivestreamSegment>  $segments
     * @return list<LivestreamSegment>
     */
    private function identifySermonCandidate(array $segments): array
    {
        $speechSegments = array_filter($segments, fn ($s) => $s->isSpeech());

        if (empty($speechSegments)) {
            return $segments;
        }

        usort($speechSegments, fn ($a, $b) => $b->duration <=> $a->duration);
        $longestSpeechSegment = $speechSegments[0];

        if ($longestSpeechSegment->duration >= $this->minSermonDuration) {
            foreach ($segments as $segment) {
                if ($segment->startTime === $longestSpeechSegment->startTime &&
                    $segment->endTime === $longestSpeechSegment->endTime) {
                    $segment->isSermonCandidate = true;
                    break;
                }
            }
        }

        return $segments;
    }

    /**
     * Extract technical metadata from a video file using FFprobe.
     *
     * @param  string  $videoPath  Absolute path to the video file
     * @return array{
     *     duration: float,
     *     format_name: string,
     *     size: int,
     *     bit_rate: int,
     *     width: int|null,
     *     height: int|null,
     *     codec: string|null
     * }
     *
     * @throws \Exception If FFprobe fails to read the file
     */
    public function getVideoMetadata(string $videoPath): array
    {
        // In testing environment, return mock metadata
        if (! isset($this->ffprobe)) {
            return [
                'duration' => 3600.0,
                'format_name' => 'mp4',
                'size' => 1024000,
                'bit_rate' => 128000,
                'width' => 1920,
                'height' => 1080,
                'codec' => 'h264',
            ];
        }

        try {
            $format = $this->ffprobe->format($videoPath);
            $video = $this->ffprobe->streams($videoPath)->videos()->first();

            return [
                'duration' => (float) $format->get('duration'),
                'format_name' => $format->get('format_name'),
                'size' => (int) $format->get('size'),
                'bit_rate' => (int) $format->get('bit_rate'),
                'width' => $video ? $video->get('width') : null,
                'height' => $video ? $video->get('height') : null,
                'codec' => $video ? $video->get('codec_name') : null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to extract video metadata', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath,
            ]);

            throw $e;
        }
    }

    /**
     * Verify that a file is a valid video format supported by the pipeline.
     *
     * @param  string  $videoPath  Absolute path to the video file
     * @return bool True if the format is recognized and supported
     */
    public function validateVideoFile(string $videoPath): bool
    {
        // In testing environment, always return true for validation
        if (! $this->ffprobe) {
            return true;
        }

        try {
            $format = $this->ffprobe->format($videoPath);
            $formatName = $format->get('format_name');

            // Get supported formats from the unified media-processing config
            // For livestream processing, we accept all video types (livestream, video, and their formats)
            $livestreamExtensions = config('media-processing.types.livestream.allowed_extensions', []);
            $videoExtensions = config('media-processing.types.video.allowed_extensions', []);

            // Ensure we have arrays (defensive programming)
            $livestreamExtensions = is_array($livestreamExtensions) ? $livestreamExtensions : [];
            $videoExtensions = is_array($videoExtensions) ? $videoExtensions : [];
            $supportedExtensions = array_merge($livestreamExtensions, $videoExtensions);

            // If no extensions found, fall back to common video extensions
            if (empty($supportedExtensions)) {
                $supportedExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
                Log::warning('Video validation using fallback extensions', [
                    'video_path' => $videoPath,
                    'fallback_extensions' => $supportedExtensions,
                ]);
            }

            // Format name mapping for FFprobe output to our expected formats
            $formatMapping = [
                'matroska,webm' => ['mkv', 'webm'],
                'mov,mp4,m4a,3gp,3g2,mj2' => ['mp4', 'mov'],
                'avi' => ['avi'],
            ];

            // Check if the detected format matches any of our supported extensions
            foreach ($formatMapping as $ffprobeFormat => $extensions) {
                if (str_contains(strtolower($formatName), $ffprobeFormat) ||
                    str_contains(strtolower($ffprobeFormat), strtolower($formatName))) {

                    // Check if any of the mapped extensions are in our supported list
                    foreach ($extensions as $ext) {
                        if (in_array($ext, $supportedExtensions)) {
                            return true;
                        }
                    }
                }
            }

            // Additional fallback checks for common format names
            $formatLower = strtolower($formatName);
            foreach ($supportedExtensions as $extension) {
                if (str_contains($formatLower, $extension)) {
                    return true;
                }
            }

            Log::warning('Unsupported video format detected', [
                'format_name' => $formatName,
                'supported_extensions' => $supportedExtensions,
                'video_path' => $videoPath,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::warning('Video validation failed', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath,
            ]);

            return false;
        }
    }

    /**
     * Calibrate a customized RMS threshold for a specific song cluster.
     *
     * Compares the signal level within known visual song samples against the
     * adjacent "speech" buffer to find the optimal separation point for
     * precise boundary detection.
     *
     * @param  string  $rmsLogPath  Relative path to the RMS log
     * @param  array{
     *     start_estimate: float,
     *     end_estimate: float,
     *     samples: array<int, float>,
     *     confidence: float,
     *     sample_count?: int,
     *     refined_visual_start?: float,
     *     refined_visual_end?: float,
     *     dense_sample_count?: int
     * }  $songCluster  Visual analysis results for the song
     * @return array{
     *     threshold: float,
     *     song_avg_rms: float,
     *     speech_avg_rms: float
     * }
     *
     * @throws SegmentationException If no RMS data exists for the specified timestamps
     */
    public function calibratePerSongThreshold(string $rmsLogPath, array $songCluster): array
    {
        try {
            $logContent = $this->storageAdapter->getFileContents($this->tempDisk, $rmsLogPath);

            $rmsData = $this->rmsAnalysisService->extractRmsData($logContent);

            $songRmsValues = $this->rmsAnalysisService->extractRmsForTimestamps($rmsData, array_values($songCluster['samples']));

            if (empty($songRmsValues)) {
                throw new SegmentationException('No RMS data found for song period');
            }

            $songAvgRms = array_sum($songRmsValues) / count($songRmsValues);

            $speechBuffer = self::CALIBRATION_SPEECH_BUFFER;
            $beforeStart = max(0, $songCluster['start_estimate'] - $speechBuffer);
            $afterEnd = $songCluster['end_estimate'];

            $beforeSpeechRms = $this->rmsAnalysisService->extractRmsForRegion($rmsData, $beforeStart, $songCluster['start_estimate']);
            $afterSpeechRms = $this->rmsAnalysisService->extractRmsForRegion($rmsData, $songCluster['end_estimate'], $afterEnd + $speechBuffer);

            $allSpeechRms = array_merge($beforeSpeechRms, $afterSpeechRms);

            if (empty($allSpeechRms)) {
                Log::warning('No adjacent speech data for threshold calibration', [
                    'song_cluster' => $songCluster,
                ]);
                $speechAvgRms = $songAvgRms - 10.0;
            } else {
                $speechAvgRms = array_sum($allSpeechRms) / count($allSpeechRms);
            }

            $threshold = ($songAvgRms + $speechAvgRms) / 2.0;

            $safetyFloor = self::THRESHOLD_SAFETY_FLOOR;
            $safetyCeiling = self::THRESHOLD_SAFETY_CEILING;
            $threshold = max($safetyFloor, min($safetyCeiling, $threshold));

            Log::info('Per-song threshold calibrated', [
                'song_avg_rms' => round($songAvgRms, 2),
                'speech_avg_rms' => round($speechAvgRms, 2),
                'threshold' => round($threshold, 2),
            ]);

            return [
                'threshold' => $threshold,
                'song_avg_rms' => $songAvgRms,
                'speech_avg_rms' => $speechAvgRms,
            ];

        } catch (\Exception $e) {
            Log::error('Per-song threshold calibration failed', [
                'error' => $e->getMessage(),
                'song_cluster' => $songCluster,
            ]);

            throw $e;
        }
    }

    /**
     * Detect precise start and end boundaries for a song cluster.
     *
     * Merges visual detection estimates with RMS signal transitions to find
     * the exact timestamps where a song begins and ends. Returns a
     * LivestreamSegment DTO populated with these boundaries.
     *
     * @param  string  $rmsLogPath  Relative path to the RMS log
     * @param  array{
     *     start_estimate: float,
     *     end_estimate: float,
     *     samples: array<int, float>,
     *     confidence: float,
     *     sample_count?: int,
     *     refined_visual_start?: float,
     *     refined_visual_end?: float,
     *     dense_sample_count?: int
     * }  $cluster  Visual analysis results for the song
     * @param  float  $threshold  The calibrated RMS threshold to use for boundary detection
     * @return LivestreamSegment  A transient segment model representing the detected song
     *
     * @throws \Exception If boundary detection logic fails
     */
    public function detectBoundariesForCluster(
        string $rmsLogPath,
        array $cluster,
        float $threshold
    ): LivestreamSegment {
        try {
            $logContent = $this->storageAdapter->getFileContents($this->tempDisk, $rmsLogPath);

            // Get refined visual boundaries if available, otherwise use estimates
            $visualStart = (float) ($cluster['refined_visual_start'] ?? $cluster['start_estimate']);
            $visualEnd = (float) ($cluster['refined_visual_end'] ?? $cluster['end_estimate']);

            // Define search region for RMS boundaries
            $introBuffer = self::INTRO_SEARCH_BUFFER;
            $outroBuffer = self::OUTRO_SEARCH_BUFFER;

            $searchStart = max(0, $visualStart - $introBuffer);
            $searchEnd = $visualEnd + $outroBuffer;

            // Parse RMS sections within search region
            $sections = $this->rmsAnalysisService->parseAudioSections($logContent, $threshold, 0); // No min duration for boundary detection

            $candidateSections = array_values(array_filter($sections, function (array $section) use ($searchStart, $searchEnd): bool {
                return ! ($section['end'] < $searchStart || $section['start'] > $searchEnd);
            }));

            $seedSection = $this->selectBestMergedSection($candidateSections, $cluster, $visualStart, $visualEnd);
            $bestSection = $this->expandSectionAroundSeed($candidateSections, $seedSection, $visualStart, $visualEnd);

            // Determine final boundaries using min/max approach
            if ($bestSection === null) {
                // No RMS section found, use visual boundaries only
                Log::warning('No RMS section found for cluster, using visual boundaries only', [
                    'visual_start' => gmdate('H:i:s', (int) $visualStart),
                    'visual_end' => gmdate('H:i:s', (int) $visualEnd),
                ]);

                $finalStart = $visualStart;
                $finalEnd = $visualEnd;
                $boundaryMethod = 'visual_only';
            } else {
                // Use min/max approach: earlier start, later end
                $rmsStart = $bestSection['start'];
                $rmsEnd = $bestSection['end'];

                $finalStart = max($visualStart, $rmsStart - self::VISUAL_START_LEAD_CAP_SECONDS);
                $finalEnd = max($visualEnd, $rmsEnd);
                $boundaryMethod = 'visual_rms_union';

                Log::info('Boundaries determined using min/max approach', [
                    'visual_start' => gmdate('H:i:s', (int) $visualStart),
                    'rms_start' => gmdate('H:i:s', (int) $rmsStart),
                    'final_start' => gmdate('H:i:s', (int) $finalStart),
                    'visual_end' => gmdate('H:i:s', (int) $visualEnd),
                    'rms_end' => gmdate('H:i:s', (int) $rmsEnd),
                    'final_end' => gmdate('H:i:s', (int) $finalEnd),
                ]);
            }

            // Calculate RMS for final segment
            $rmsData = $this->rmsAnalysisService->extractRmsData($logContent);
            $segmentRms = $this->rmsAnalysisService->calculateSegmentRms($finalStart, $finalEnd, $rmsData);

            return new LivestreamSegment(
                startTime: $finalStart,
                endTime: $finalEnd,
                duration: $finalEnd - $finalStart,
                classification: LivestreamSegmentClassification::Song->value,
                avgRms: $segmentRms['avg'],
                peakRms: $segmentRms['peak'],
                isSermonCandidate: false,
                segmentOrder: 0, // Will be set by caller
                metadata: [
                    'threshold_used' => $threshold,
                    'visual_sample_count' => count($cluster['samples']),
                    'visual_confidence' => $cluster['confidence'],
                    'calibration_method' => 'per_song_visual',
                    'boundary_method' => $boundaryMethod,
                    'visual_start' => $visualStart,
                    'visual_end' => $visualEnd,
                    'rms_start' => $bestSection['start'] ?? null,
                    'rms_end' => $bestSection['end'] ?? null,
                ]
            );

        } catch (\Exception $e) {
            Log::error('Boundary detection failed', [
                'error' => $e->getMessage(),
                'cluster' => $cluster,
            ]);

            throw $e;
        }
    }

    /**
     * @param  list<array{start: float, end: float}>  $sections
     * @param  array{
     *     start_estimate: float,
     *     end_estimate: float,
     *     samples: array<int, float>,
     *     confidence: float,
     *     sample_count?: int,
     *     refined_visual_start?: float,
     *     refined_visual_end?: float,
     *     dense_sample_count?: int
     * }  $cluster
     * @return array{start: float, end: float}|null
     */
    private function selectBestMergedSection(array $sections, array $cluster, float $visualStart, float $visualEnd): ?array
    {
        if ($sections === []) {
            return null;
        }

        $samples = $cluster['samples'];
        $visualMidpoint = $visualStart + (($visualEnd - $visualStart) / 2.0);

        usort($sections, function (array $left, array $right) use ($samples, $visualStart, $visualEnd, $visualMidpoint): int {
            $leftAnchors = $this->countClusterSamplesInSection($left, $samples);
            $rightAnchors = $this->countClusterSamplesInSection($right, $samples);

            if ($leftAnchors !== $rightAnchors) {
                return $rightAnchors <=> $leftAnchors;
            }

            $leftOverlap = $this->calculateOverlap($left['start'], $left['end'], $visualStart, $visualEnd);
            $rightOverlap = $this->calculateOverlap($right['start'], $right['end'], $visualStart, $visualEnd);

            if ($leftOverlap !== $rightOverlap) {
                return $rightOverlap <=> $leftOverlap;
            }

            $leftDistance = abs((($left['start'] + $left['end']) / 2.0) - $visualMidpoint);
            $rightDistance = abs((($right['start'] + $right['end']) / 2.0) - $visualMidpoint);

            return $leftDistance <=> $rightDistance;
        });

        return $sections[0];
    }

    /**
     * @param  list<array{start: float, end: float}>  $sections
     * @param  array{start: float, end: float}|null  $seedSection
     * @return array{start: float, end: float}|null
     */
    private function expandSectionAroundSeed(array $sections, ?array $seedSection, float $visualStart, float $visualEnd): ?array
    {
        if ($seedSection === null || $sections === []) {
            return $seedSection;
        }

        usort($sections, fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $seedIndex = null;
        foreach ($sections as $index => $section) {
            if ($section['start'] === $seedSection['start'] && $section['end'] === $seedSection['end']) {
                $seedIndex = $index;
                break;
            }
        }

        if ($seedIndex === null) {
            return $seedSection;
        }

        $expanded = $seedSection;

        for ($index = $seedIndex + 1; $index < count($sections); $index++) {
            $section = $sections[$index];
            if (($section['start'] - $expanded['end']) > self::RMS_SECTION_MERGE_GAP) {
                break;
            }
            if ($section['start'] > ($visualEnd + self::OUTRO_SEARCH_BUFFER)) {
                break;
            }

            $expanded['end'] = max($expanded['end'], $section['end']);
        }

        for ($index = $seedIndex - 1; $index >= 0; $index--) {
            $section = $sections[$index];
            if (($expanded['start'] - $section['end']) > self::RMS_SECTION_MERGE_GAP) {
                break;
            }
            if ($section['end'] < ($visualStart - self::VISUAL_START_LEAD_CAP_SECONDS)) {
                break;
            }

            $expanded['start'] = min($expanded['start'], $section['start']);
        }

        return $expanded;
    }

    /**
     * @param  array{start: float, end: float}  $section
     * @param  array<int, float>  $samples
     */
    private function countClusterSamplesInSection(array $section, array $samples): int
    {
        return count(array_filter($samples, fn (float $sample): bool => $sample >= $section['start'] && $sample <= $section['end']));
    }

    private function calculateOverlap(float $leftStart, float $leftEnd, float $rightStart, float $rightEnd): float
    {
        $overlapStart = max($leftStart, $rightStart);
        $overlapEnd = min($leftEnd, $rightEnd);

        return max(0.0, $overlapEnd - $overlapStart);
    }
}
