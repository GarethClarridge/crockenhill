<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FrameExtractionService
{
    private string $tempDisk;

    private string $tempPath;

    /** @var array<string, mixed> */
    private array $ffmpegConfig;

    /** @var array<string, mixed> */
    private array $extractionConfig;

    public function __construct(
        private readonly VideoSegmentationService $videoService
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

            $process = new \Symfony\Component\Process\Process($command);
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
     * Get video metadata using VideoSegmentationService
     *
     * @return array<string, float|int|string>
     */
    public function getVideoMetadata(string $videoPath): array
    {
        try {
            return $this->videoService->getVideoMetadata($videoPath);
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
     * Ensure we have a local path for FFmpeg processing
     */
    public function ensureLocalVideoPath(string $videoPath, ?string $disk = null): string
    {
        if (! $disk) {
            return $videoPath;
        }

        $diskInstance = Storage::disk($disk);

        if ($this->isS3CompatibleDisk($diskInstance)) {
            $tempVideoFilename = 'temp_video_'.Str::uuid().'.'.pathinfo($videoPath, PATHINFO_EXTENSION);
            $tempVideoPath = $this->tempPath.'/'.$tempVideoFilename;

            Storage::disk($this->tempDisk)->makeDirectory($this->tempPath);

            $localTempPath = Storage::disk($this->tempDisk)->path($tempVideoPath);

            $stream = $diskInstance->readStream($videoPath);
            Storage::disk($this->tempDisk)->writeStream($tempVideoPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $localTempPath;
        }

        return $diskInstance->path($videoPath);
    }

    /**
     * Check if a disk uses S3-compatible storage
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     */
    public function isS3CompatibleDisk(mixed $disk): bool
    {
        try {
            $adapter = $disk->getAdapter();

            return $adapter instanceof \League\Flysystem\AwsS3V3\AwsS3V3Adapter;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clean up downloaded video file (for S3 processing)
     */
    public function cleanupDownloadedVideo(?string $tempVideoPath): void
    {
        if (! $tempVideoPath) {
            return;
        }

        try {
            $cleanupEnabled = config('thumbnail-generation.processing.cleanup_temp_files');
            if ($cleanupEnabled && file_exists($tempVideoPath)) {
                unlink($tempVideoPath);
                Log::debug('Cleaned up downloaded S3 video temp file', [
                    'temp_video_path' => $tempVideoPath,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup downloaded video temp file', [
                'temp_video_path' => $tempVideoPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
