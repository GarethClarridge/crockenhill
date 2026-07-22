<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\LivestreamSegment;
use App\Enums\LivestreamSegmentClassification;
use App\Exceptions\SegmentationException;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Processing\StorageAdapterHelper;
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
    private ?FFProbe $ffprobe = null;

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

        $this->tempDisk = config()->string('media-processing.storage.temp_disk', 'local');
    }

    /**
     * Generate an RMS loudness log for a video file using FFmpeg.
     *
     * Extracts precise loudness data at 8kHz for performance, producing a log
     * that maps PTS timestamps to RMS levels.
     *
     * @param  string  $videoPath  Absolute path to the source video file
     * @return string Relative path to the generated RMS log on the temporary disk
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

        return $this->markSermonCandidate($segments);
    }

    /**
     * Mark the longest speech segment when it meets the minimum sermon duration.
     *
     * @param  list<LivestreamSegment>  $segments
     * @return list<LivestreamSegment>
     */
    private function markSermonCandidate(array $segments): array
    {
        $minimumDuration = (float) config('media-processing.segmentation.min_sermon_duration', 300.0);
        $candidateIndex = null;
        $candidateDuration = 0.0;

        foreach ($segments as $index => $segment) {
            if (! $segment->isSpeech() || $segment->duration < $minimumDuration) {
                continue;
            }

            if ($segment->duration > $candidateDuration) {
                $candidateIndex = $index;
                $candidateDuration = $segment->duration;
            }
        }

        if ($candidateIndex !== null) {
            $segments[$candidateIndex]->isSermonCandidate = true;
        }

        return $segments;
    }

    /**
     * @param  array<int, array{start: float, end: float}>  $loudSections
     * @param  list<array{time: float, rms: float}>  $rmsData
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
}
