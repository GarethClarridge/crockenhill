<?php

namespace App\Services;

use App\Data\LivestreamSegment;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoSegmentationService
{
    /** @phpstan-ignore-next-line property.unusedType (nullable for testing environment) */
    private ?FFProbe $ffprobe;

    private float $rmsThreshold;

    private float $minSectionDuration;

    private float $minSermonDuration;

    private string $tempDisk;

    private array $adaptiveConfig;

    public function __construct()
    {
        // Skip FFProbe initialization in testing environment to prevent hangs
        if (! app()->environment('testing')) {
            $this->ffprobe = FFProbe::create([
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);
        }

        $this->rmsThreshold = config('media-processing.segmentation.rms_threshold', -45.0);
        $this->minSectionDuration = config('media-processing.segmentation.min_section_duration', 30.0);
        $this->minSermonDuration = config('media-processing.segmentation.min_sermon_duration', 300.0);
        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
        $this->adaptiveConfig = config('media-processing.segmentation.adaptive_thresholds', []);
    }

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

            $process = new \Symfony\Component\Process\Process($command);
            $process->setTimeout(7200); // 2 hour timeout for large files
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
            }

            if (! $this->fileExists($fullRmsLogPath, $rmsLogPath) || $this->getFileSize($fullRmsLogPath, $rmsLogPath) === 0) {
                throw new \Exception('Failed to generate RMS log file or file is empty');
            }

            Log::info('RMS log generated successfully', ['path' => $rmsLogPath, 'size' => $this->getFileSize($fullRmsLogPath, $rmsLogPath)]);

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
     * @return array{segments: LivestreamSegment[], threshold_metadata: array}
     */
    public function analyzeSegments(string $rmsLogPath): array
    {
        try {
            $fullRmsLogPath = Storage::disk($this->tempDisk)->path($rmsLogPath);

            if (! $this->fileExists($fullRmsLogPath, $rmsLogPath)) {
                throw new \Exception('RMS log file not found: '.$fullRmsLogPath);
            }

            $logContent = $this->getFileContents($fullRmsLogPath, $rmsLogPath);

            $thresholdResult = $this->determineThreshold($logContent);

            Log::info('Threshold determination result', $thresholdResult['log_data']);

            $segments = $this->parseRmsLog($logContent, $thresholdResult['threshold']);

            Log::info('Segments analyzed', [
                'total_segments' => count($segments),
                'speech_segments' => count(array_filter($segments, fn ($s) => $s->isSpeech())),
                'song_segments' => count(array_filter($segments, fn ($s) => $s->isSong())),
                'threshold_used' => $thresholdResult['threshold'],
                'method_used' => $thresholdResult['method'],
            ]);

            // Return segments with threshold metadata for storage
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
     * @return LivestreamSegment[]
     */
    private function parseRmsLog(string $logContent, ?float $adaptiveThreshold = null): array
    {
        $lines = explode("\n", trim($logContent));
        $threshold = $adaptiveThreshold ?? $this->rmsThreshold;
        $loudSections = $this->parseAudioSections($logContent, $threshold);

        // Get total duration from the last timestamp
        $totalDuration = 0.0;
        foreach ($lines as $line) {
            if (preg_match('/frame\.pts_time=(\d+\.\d+)/', $line, $matches)) {
                $totalDuration = max($totalDuration, (float) $matches[1]);
            }
        }

        // Parse all RMS data for calculating actual segment averages
        $rmsData = $this->extractRmsData($logContent);

        $segments = $this->combineLoudAndQuietSections($loudSections, $totalDuration, $rmsData);

        return $this->identifySermonCandidate($segments);
    }

    private function parseAudioSections(string $logContent, ?float $threshold = null, ?float $minSectionDuration = null): array
    {
        $threshold = $threshold ?? $this->rmsThreshold;
        $minSectionDuration = $minSectionDuration ?? $this->minSectionDuration;

        $lines = explode("\n", trim($logContent));

        // Get total duration from pts_time values
        $totalDuration = $this->getTotalDuration($logContent, $lines);

        $sections = [];
        $currentSection = null;
        $currentTime = 0.0;

        foreach ($lines as $line) {
            // Extract pts_time from frame lines (format: "pts_time:1234.56")
            if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $timeMatches)) {
                $currentTime = (float) $timeMatches[1];
                continue;
            }

            // Parse RMS level from lavfi lines
            if (preg_match('/lavfi\.astats\.Overall\.RMS_level=(-?\d+(?:\.\d+)?|-inf)/', $line, $rmsMatches)) {
                $rmsLevel = $rmsMatches[1] === '-inf' ? -999.0 : (float) $rmsMatches[1];

                if ($rmsLevel > $threshold) {
                    // Start a new loud section if none is active
                    if ($currentSection === null) {
                        $currentSection = ['start' => $currentTime, 'end' => null];
                    }
                } else {
                    // End the current loud section if active
                    if ($currentSection !== null) {
                        $currentSection['end'] = $currentTime;
                        // Only add the section if it meets the minimum duration
                        if (($currentSection['end'] - $currentSection['start']) >= $minSectionDuration) {
                            $sections[] = $currentSection;
                        }
                        $currentSection = null;
                    }
                }
            }
        }

        // Close any open section at end of file
        if ($currentSection !== null) {
            $currentSection['end'] = $totalDuration;
            if (($currentSection['end'] - $currentSection['start']) >= $minSectionDuration) {
                $sections[] = $currentSection;
            }
        }

        return $sections;
    }

    private function getTotalDuration(string $logContent, array $lines): float
    {
        // Try to get duration from pts_time if available (most accurate)
        $maxTime = 0.0;
        foreach ($lines as $line) {
            if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $matches)) {
                $maxTime = max($maxTime, (float) $matches[1]);
            }
        }

        // If we found pts_time, use that
        if ($maxTime > 0) {
            return $maxTime;
        }

        // Otherwise estimate based on number of lines and audio sample rate
        // This is a fallback - the Python script uses ffprobe for this
        return count($lines) / 43.0; // Rough estimate based on typical frame rates
    }

    private function combineLoudAndQuietSections(array $loudSections, float $totalDuration, array $rmsData): array
    {
        $combinedSections = [];
        $previousEnd = 0.0;
        $segmentOrder = 0;

        foreach ($loudSections as $section) {
            $start = $section['start'];
            $end = $section['end'];

            // Add quiet section before the current loud section
            if ($start > $previousEnd) {
                $speechRms = $this->calculateSegmentRms($previousEnd, $start, $rmsData);
                $combinedSections[] = new LivestreamSegment(
                    startTime: $previousEnd,
                    endTime: $start,
                    duration: $start - $previousEnd,
                    classification: 'speech',
                    avgRms: $speechRms['avg'],
                    peakRms: $speechRms['peak'],
                    segmentOrder: $segmentOrder++
                );
            }

            // Add the current loud section
            $songRms = $this->calculateSegmentRms($start, $end, $rmsData);
            $combinedSections[] = new LivestreamSegment(
                startTime: $start,
                endTime: $end,
                duration: $end - $start,
                classification: 'song',
                avgRms: $songRms['avg'],
                peakRms: $songRms['peak'],
                segmentOrder: $segmentOrder++
            );

            $previousEnd = $end;
        }

        // Add the final quiet section if it exists
        if ($previousEnd < $totalDuration) {
            $speechRms = $this->calculateSegmentRms($previousEnd, $totalDuration, $rmsData);
            $combinedSections[] = new LivestreamSegment(
                startTime: $previousEnd,
                endTime: $totalDuration,
                duration: $totalDuration - $previousEnd,
                classification: 'speech',
                avgRms: $speechRms['avg'],
                peakRms: $speechRms['peak'],
                segmentOrder: $segmentOrder
            );
        }

        return $combinedSections;
    }

    /**
     * @param  LivestreamSegment[]  $segments
     * @return LivestreamSegment[]
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
     * Determine which threshold to use based on configuration
     */
    private function determineThreshold(string $logContent): array
    {
        // Check if adaptive thresholds are enabled
        if (! ($this->adaptiveConfig['enabled'] ?? true)) {
            return [
                'threshold' => $this->rmsThreshold,
                'method' => 'fixed',
                'log_data' => [
                    'method' => 'fixed',
                    'threshold_used' => $this->rmsThreshold,
                    'reason' => 'adaptive_disabled',
                ],
            ];
        }

        // Try to calculate adaptive threshold
        $adaptiveResult = $this->calculateAdaptiveThreshold($logContent);

        if ($adaptiveResult['success']) {
            return [
                'threshold' => $adaptiveResult['threshold'],
                'method' => 'adaptive',
                'log_data' => $adaptiveResult['log_data'],
                'rms_stats' => $adaptiveResult['rms_stats'],
            ];
        }

        // Fallback to fixed threshold
        if ($this->adaptiveConfig['fallback_enabled'] ?? true) {
            return [
                'threshold' => $this->rmsThreshold,
                'method' => 'fallback',
                'log_data' => [
                    'method' => 'fallback',
                    'threshold_used' => $this->rmsThreshold,
                    'reason' => $adaptiveResult['error'] ?? 'adaptive_calculation_failed',
                ],
            ];
        }

        // If fallback is disabled and adaptive failed, throw error
        throw new \Exception('Adaptive threshold calculation failed and fallback is disabled: '.($adaptiveResult['error'] ?? 'unknown_error'));
    }

    /**
     * Calculate adaptive threshold based on RMS distribution
     */
    private function calculateAdaptiveThreshold(string $logContent): array
    {
        try {
            $rmsValues = [];
            $lines = explode("\n", trim($logContent));

            foreach ($lines as $line) {
                if (preg_match('/lavfi\.astats\.Overall\.RMS_level=(-?\d+(?:\.\d+)?|-inf)/', $line, $matches)) {
                    $rmsLevel = $matches[1] === '-inf' ? -999.0 : (float) $matches[1];
                    if ($rmsLevel > -999.0) { // Exclude silence markers
                        $rmsValues[] = $rmsLevel;
                    }
                }
            }

            $minSampleCount = $this->adaptiveConfig['min_sample_count'] ?? 1000;
            if (count($rmsValues) < $minSampleCount) {
                return [
                    'success' => false,
                    'error' => 'insufficient_samples',
                    'sample_count' => count($rmsValues),
                    'min_required' => $minSampleCount,
                ];
            }

            sort($rmsValues);
            $count = count($rmsValues);

            // Use configured percentile (default 30th)
            $speechPercentile = ($this->adaptiveConfig['speech_percentile'] ?? 30) / 100.0;
            $speechThreshold = $rmsValues[(int) floor($count * $speechPercentile)];

            // Validate threshold is within bounds
            $minThreshold = $this->adaptiveConfig['min_threshold'] ?? -80.0;
            $maxThreshold = $this->adaptiveConfig['max_threshold'] ?? -20.0;

            $adaptiveThreshold = max($minThreshold, min($maxThreshold, $speechThreshold));

            // Calculate statistics for logging
            $mean = array_sum($rmsValues) / $count;
            $p25 = $rmsValues[(int) floor($count * 0.25)];
            $p50 = $rmsValues[(int) floor($count * 0.50)];
            $p75 = $rmsValues[(int) floor($count * 0.75)];

            return [
                'success' => true,
                'threshold' => $adaptiveThreshold,
                'log_data' => [
                    'method' => 'adaptive',
                    'threshold_used' => $adaptiveThreshold,
                    'raw_threshold' => $speechThreshold,
                    'sample_count' => $count,
                    'percentile_used' => $speechPercentile * 100,
                    'bounds_applied' => $speechThreshold !== $adaptiveThreshold,
                ],
                'rms_stats' => [
                    'sample_count' => $count,
                    'min' => min($rmsValues),
                    'max' => max($rmsValues),
                    'mean' => $mean,
                    'p25' => $p25,
                    'p50' => $p50,
                    'p75' => $p75,
                    'adaptive_threshold' => $adaptiveThreshold,
                ],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract all RMS data with timestamps for segment calculation
     */
    private function extractRmsData(string $logContent): array
    {
        $lines = explode("\n", trim($logContent));
        $rmsData = [];
        $currentTime = 0.0;

        foreach ($lines as $line) {
            // Extract pts_time from frame lines (format: "pts_time:1234.56")
            if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $timeMatches)) {
                $currentTime = (float) $timeMatches[1];
                continue;
            }

            if (preg_match('/lavfi\.astats\.Overall\.RMS_level=(-?\d+(?:\.\d+)?|-inf)/', $line, $matches)) {
                $rmsLevel = $matches[1] === '-inf' ? -999.0 : (float) $matches[1];

                $rmsData[] = [
                    'time' => $currentTime,
                    'rms' => $rmsLevel,
                ];
            }
        }

        return $rmsData;
    }

    /**
     * Calculate actual average and peak RMS for a segment
     */
    private function calculateSegmentRms(float $startTime, float $endTime, array $rmsData): array
    {
        $segmentRms = [];

        foreach ($rmsData as $data) {
            if ($data['time'] >= $startTime && $data['time'] <= $endTime && $data['rms'] > -999.0) {
                $segmentRms[] = $data['rms'];
            }
        }

        if (empty($segmentRms)) {
            // Fallback to reasonable defaults if no data
            return ['avg' => -50.0, 'peak' => -40.0];
        }

        $avgRms = array_sum($segmentRms) / count($segmentRms);
        $peakRms = max($segmentRms);

        return [
            'avg' => round($avgRms, 1),
            'peak' => round($peakRms, 1),
        ];
    }

    public function getVideoMetadata(string $videoPath): array
    {
        // In testing environment, return mock metadata
        if (!isset($this->ffprobe)) {
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

    public function validateVideoFile(string $videoPath): bool
    {
        // In testing environment, always return true for validation
        if (!$this->ffprobe) {
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
     * Check if file exists on specified disk (S3-aware)
     */
    private function fileExists(string $fullPath, string $storagePath): bool
    {
        if ($this->isS3Disk($this->tempDisk)) {
            return Storage::disk($this->tempDisk)->exists($storagePath);
        }

        return file_exists($fullPath);
    }

    /**
     * Get file size on specified disk (S3-aware)
     */
    private function getFileSize(string $fullPath, string $storagePath): int
    {
        if ($this->isS3Disk($this->tempDisk)) {
            try {
                return Storage::disk($this->tempDisk)->size($storagePath);
            } catch (\Exception $e) {
                return 0;
            }
        }

        return file_exists($fullPath) ? filesize($fullPath) : 0;
    }

    /**
     * Get file contents (S3-aware)
     */
    private function getFileContents(string $fullPath, string $storagePath): string
    {
        if ($this->isS3Disk($this->tempDisk)) {
            return Storage::disk($this->tempDisk)->get($storagePath);
        }

        return file_get_contents($fullPath);
    }

    /**
     * Check if the disk is S3-compatible (DigitalOcean Spaces, AWS S3, etc.)
     */
    private function isS3Disk(string $diskName): bool
    {
        $diskConfig = config("filesystems.disks.{$diskName}");

        return isset($diskConfig['driver']) && $diskConfig['driver'] === 's3';
    }
}
