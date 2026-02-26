<?php

namespace App\Services;

use App\Data\LivestreamSegment;
use App\Exceptions\SegmentationException;
use App\Traits\DetectsStorageType;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoSegmentationService
{
    use DetectsStorageType;

    private ?FFProbe $ffprobe = null;

    private float $minSermonDuration;

    private string $tempDisk;

    private readonly RmsAnalysisService $rmsAnalysisService;

    public function __construct(RmsAnalysisService $rmsAnalysisService)
    {
        // Skip FFProbe initialization in testing environment to prevent hangs
        if (! app()->environment('testing')) {
            $this->ffprobe = FFProbe::create([
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);
        }

        $this->rmsAnalysisService = $rmsAnalysisService;
        $this->minSermonDuration = config('media-processing.segmentation.min_sermon_duration', 300.0);
        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
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
                throw new SegmentationException('Failed to generate RMS log file or file is empty');
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
     * @return array<string, mixed>
     */
    public function analyzeSegments(string $rmsLogPath): array
    {
        try {
            $fullRmsLogPath = Storage::disk($this->tempDisk)->path($rmsLogPath);

            if (! $this->fileExists($fullRmsLogPath, $rmsLogPath)) {
                throw new SegmentationException('RMS log file not found: '.$fullRmsLogPath);
            }

            $logContent = $this->getFileContents($fullRmsLogPath, $rmsLogPath);

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
     * @return LivestreamSegment[]
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
     * @param  array<int, array<string, float>>  $loudSections
     * @param  array<string, mixed>  $rmsData
     * @return array<int, LivestreamSegment>
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
                    classification: 'speech',
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
                classification: 'song',
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
     * @return array<string, float|int|string|null>
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

        if (! file_exists($fullPath)) {
            return 0;
        }

        $fileSize = filesize($fullPath);

        return $fileSize === false ? 0 : $fileSize;
    }

    /**
     * Get file contents (S3-aware)
     */
    private function getFileContents(string $fullPath, string $storagePath): string
    {
        if ($this->isS3Disk($this->tempDisk)) {
            $contents = Storage::disk($this->tempDisk)->get($storagePath);

            return is_string($contents) ? $contents : '';
        }

        $contents = file_get_contents($fullPath);

        return $contents === false ? '' : $contents;
    }

    /**
     * Calibrate per-song RMS threshold based on song and adjacent speech
     *
     * @param  array{
     *     start_estimate: float,
     *     end_estimate: float,
     *     samples: array<int, float>,
     *     confidence: float,
     *     sample_count?: int,
     *     refined_visual_start?: float,
     *     refined_visual_end?: float,
     *     dense_sample_count?: int
     * }  $songCluster
     * @return array<string, float>
     */
    public function calibratePerSongThreshold(string $rmsLogPath, array $songCluster): array
    {
        try {
            $fullRmsLogPath = Storage::disk($this->tempDisk)->path($rmsLogPath);
            $logContent = $this->getFileContents($fullRmsLogPath, $rmsLogPath);

            $rmsData = $this->rmsAnalysisService->extractRmsData($logContent);

            $songRmsValues = $this->rmsAnalysisService->extractRmsForTimestamps($rmsData, $songCluster['samples']);

            if (empty($songRmsValues)) {
                throw new SegmentationException('No RMS data found for song period');
            }

            $songAvgRms = array_sum($songRmsValues) / count($songRmsValues);

            $speechBuffer = (float) config('media-processing.visual_analysis.calibration_speech_buffer', 60);
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

            $safetyFloor = (float) config('media-processing.visual_analysis.threshold_safety_floor', -80.0);
            $safetyCeiling = (float) config('media-processing.visual_analysis.threshold_safety_ceiling', -20.0);
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
     * Detect precise boundaries for a song cluster using min/max of visual and RMS boundaries
     *
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
     */
    public function detectBoundariesForCluster(
        string $rmsLogPath,
        array $cluster,
        float $threshold
    ): LivestreamSegment {
        try {
            $fullRmsLogPath = Storage::disk($this->tempDisk)->path($rmsLogPath);
            $logContent = $this->getFileContents($fullRmsLogPath, $rmsLogPath);

            // Get refined visual boundaries if available, otherwise use estimates
            $visualStart = (float) ($cluster['refined_visual_start'] ?? $cluster['start_estimate']);
            $visualEnd = (float) ($cluster['refined_visual_end'] ?? $cluster['end_estimate']);

            // Define search region for RMS boundaries
            $introBuffer = (float) config('media-processing.visual_analysis.intro_search_buffer', 120);
            $outroBuffer = (float) config('media-processing.visual_analysis.outro_search_buffer', 60);

            $searchStart = max(0, $visualStart - $introBuffer);
            $searchEnd = $visualEnd + $outroBuffer;

            // Parse RMS sections within search region
            $sections = $this->rmsAnalysisService->parseAudioSections($logContent, $threshold, 0); // No min duration for boundary detection

            // Find RMS section that best overlaps with visual boundaries
            $bestSection = null;
            $bestOverlap = 0;

            foreach ($sections as $section) {
                // Check if section overlaps with search region
                if ($section['end'] < $searchStart || $section['start'] > $searchEnd) {
                    continue;
                }

                // Calculate overlap with visual boundaries
                $overlapStart = max($section['start'], $visualStart);
                $overlapEnd = min($section['end'], $visualEnd);
                $overlap = max(0, $overlapEnd - $overlapStart);

                if ($overlap > $bestOverlap) {
                    $bestOverlap = $overlap;
                    $bestSection = $section;
                }
            }

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

                $finalStart = min($visualStart, $rmsStart);
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
                classification: 'song',
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
}
