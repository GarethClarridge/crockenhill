<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use App\Services\StorageAdapterHelper;

class FrameExtractionService
{
    private string $tempDisk;

    private string $tempPath;

    /** @var array<string, mixed> */
    private array $ffmpegConfig;

    /** @var array<string, mixed> */
    private array $extractionConfig;

    public function __construct(
        private readonly VideoSegmentationService $videoService,
        private readonly StorageAdapterHelper $storageHelper
    ) {
        $config = config('thumbnail-generation');

        $this->tempDisk = $config['processing']['temp_disk'];
        $this->tempPath = $config['processing']['temp_path'];
        $this->ffmpegConfig = $config['ffmpeg'];
        $this->extractionConfig = $config['extraction'];
    }

    /**
     * Extract base frame from video at specified timestamp
     *
     * @return string|null Path to extracted frame or null on failure
     */
    public function extractBaseFrame(string $videoPath, float $timestamp): ?string
    {
        try {
            $frameFilename = 'frame_'.Str::uuid().'.webp';
            $tempFramePath = $this->tempPath.'/'.$frameFilename;

            Storage::disk($this->tempDisk)->makeDirectory($this->tempPath);

            $fullTempPath = Storage::disk($this->tempDisk)->path($tempFramePath);

            $command = [
                $this->ffmpegConfig['path'],
                '-threads', (string) $this->ffmpegConfig['threads'],
                '-ss', (string) $timestamp,
                '-i', $videoPath,
                '-vframes', '1',
                '-q:v', '2',
                '-y',
                $fullTempPath,
            ];

            $process = new Process($command);
            $process->setTimeout($this->ffmpegConfig['timeout']);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::error('FFmpeg frame extraction failed', [
                    'command' => implode(' ', $command),
                    'error' => $process->getErrorOutput(),
                ]);

                return null;
            }

            if (! file_exists($fullTempPath) || filesize($fullTempPath) === 0) {
                Log::error('Frame extraction produced no output', [
                    'temp_path' => $fullTempPath,
                ]);

                return null;
            }

            return $tempFramePath;

        } catch (\Exception $e) {
            Log::error('Frame extraction exception', [
                'video_path' => $videoPath,
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Calculate optimal timestamp for frame extraction based on video duration
     */
    public function calculateOptimalTimestamp(float $duration): float
    {
        $startOffset = $this->extractionConfig['start_offset'];
        $endBuffer = $this->extractionConfig['end_buffer'];
        $fallbackPosition = $this->extractionConfig['fallback_position'];

        if ($duration <= ((float) $startOffset + (float) $endBuffer)) {
            return $duration * $fallbackPosition;
        }

        $maxTimestamp = $duration - $endBuffer;
        $targetTimestamp = $startOffset;

        return min($targetTimestamp, $maxTimestamp);
    }

    /**
     * Calculate evenly spaced candidate timestamps for multi-thumbnail extraction.
     *
     * @return list<float>
     */
    public function calculateCandidateTimestamps(float $duration, int $count = 5): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($duration <= 0) {
            return [0.0];
        }

        $startOffset = (float) $this->extractionConfig['start_offset'];
        $endBuffer = (float) $this->extractionConfig['end_buffer'];
        $fallbackPosition = (float) $this->extractionConfig['fallback_position'];

        $windowStart = $startOffset;
        $windowEnd = max(0.0, $duration - $endBuffer);

        if ($windowEnd <= $windowStart) {
            $windowStart = max(0.0, $duration * max(0.05, $fallbackPosition - 0.3));
            $windowEnd = min($duration, $duration * min(0.95, $fallbackPosition + 0.3));
        }

        $windowStart = max(0.0, min($windowStart, $duration));
        $windowEnd = max($windowStart, min($windowEnd, $duration));

        if (abs($windowEnd - $windowStart) < 0.001) {
            return [(float) number_format($windowStart, 3, '.', '')];
        }

        $timestamps = [];
        $interval = ($windowEnd - $windowStart) / max(1, $count - 1);

        for ($index = 0; $index < $count; $index++) {
            $timestamp = max(0.0, min($windowStart + ($interval * $index), $duration));
            $key = number_format($timestamp, 3, '.', '');

            if (! array_key_exists($key, $timestamps)) {
                $timestamps[$key] = (float) $key;
            }
        }

        return array_values($timestamps);
    }

    /**
     * Get video metadata using VideoSegmentationService
     *
     * @return array<string, float|int|string>
     */
    public function getVideoMetadata(string $videoPath): array
    {
        try {
            $metadata = $this->videoService->getVideoMetadata($videoPath);

            return [
                'duration' => $metadata['duration'],
                'width' => (int) ($metadata['width'] ?? 1920),
                'height' => (int) ($metadata['height'] ?? 1080),
                'format_name' => $metadata['format_name'],
                'size' => $metadata['size'],
                'bit_rate' => $metadata['bit_rate'],
                'codec' => (string) ($metadata['codec'] ?? 'unknown'),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get video metadata for thumbnail', [
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'duration' => 0.0,
                'width' => 1920,
                'height' => 1080,
                'format_name' => 'unknown',
                'size' => 0,
                'bit_rate' => 0,
                'codec' => 'unknown',
            ];
        }
    }

    /**
     * Check if video file exists using storage-aware method
     */
    public function videoFileExists(string $videoPath, ?string $disk = null): bool
    {
        if ($disk) {
            return Storage::disk($disk)->exists($videoPath);
        }

        return file_exists($videoPath);
    }

    /**
     * Ensure we have a local path for FFmpeg processing.
     * Downloads S3 files into the service's temp directory when needed.
     */
    public function ensureLocalVideoPath(string $videoPath, ?string $disk = null): string
    {
        if (! $disk) {
            return $videoPath;
        }

        return $this->storageHelper->downloadToTemp($videoPath, $disk, $this->tempDisk, $this->tempPath);
    }

    /**
     * Clean up downloaded video file (for S3 processing)
     */
    public function cleanupDownloadedVideo(?string $tempVideoPath): void
    {
        if (! $tempVideoPath) {
            return;
        }

        if (config('thumbnail-generation.processing.cleanup_temp_files')) {
            $this->storageHelper->cleanupTempFile($tempVideoPath);
        }
    }
}
