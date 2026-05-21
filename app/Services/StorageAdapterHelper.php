<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\VideoProcessingException;
use FFMpeg\FFMpeg;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/**
 * Centralises storage adapter detection and the three recurring S3/local patterns:
 *
 * 1. isS3CompatibleDisk     — runtime adapter check (AwsS3V3)
 * 2. downloadToTemp         — download an S3 file to a local temp path for processing
 * 3. cleanupTempFile        — safely remove a local temp file
 * 4. uploadWithRetry        — upload a local file to a named disk with exponential-backoff retry
 * 5. getProcessingOutputPath — return the correct local processing path and permanent path
 *                              depending on whether the target disk is S3 or local
 */
class StorageAdapterHelper
{
    /**
     * Check if a disk instance uses an S3-compatible adapter at runtime.
     */
    public function isS3CompatibleDisk(Filesystem $disk): bool
    {
        try {
            $adapter = $disk->getAdapter();

            return $adapter instanceof AwsS3V3Adapter;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Ensure a local absolute path exists for the given file.
     *
     * When the disk is S3-compatible, the file is downloaded into
     * `{tempDisk}/{tempSubdir}/{uuid}.{ext}` and that absolute path is returned.
     * When the disk is local, the absolute path on that disk is returned directly.
     *
     * @param  string  $relativePath  Relative path on the disk
     * @param  string  $diskName  Named filesystem disk
     * @param  string  $tempDiskName  Local disk to use for temporary downloads
     * @param  string  $tempSubdir  Subdirectory inside the temp disk (e.g. 'temp/ffprobe')
     * @return string Absolute local file path
     */
    public function downloadToTemp(
        string $relativePath,
        string $diskName,
        string $tempDiskName = 'local',
        string $tempSubdir = 'temp/processing'
    ): string {
        $disk = Storage::disk($diskName);

        if ($this->isS3CompatibleDisk($disk)) {
            $ext = pathinfo($relativePath, PATHINFO_EXTENSION);
            $tempFilename = Str::uuid().($ext ? ".{$ext}" : '');
            $tempRelativePath = $tempSubdir.'/'.$tempFilename;

            Storage::disk($tempDiskName)->makeDirectory($tempSubdir);
            $localTempPath = Storage::disk($tempDiskName)->path($tempRelativePath);

            $stream = $disk->readStream($relativePath);
            if (! is_resource($stream)) {
                throw new VideoProcessingException("Unable to read file stream for temporary download: {$relativePath}");
            }

            Storage::disk($tempDiskName)->writeStream($tempRelativePath, $stream);
            fclose($stream);

            return $localTempPath;
        }

        return $disk->path($relativePath);
    }

    /**
     * Delete a local file, logging a warning on failure (never throws).
     */
    public function cleanupTempFile(string $absolutePath): void
    {
        if (empty($absolutePath) || ! file_exists($absolutePath)) {
            return;
        }

        try {
            unlink($absolutePath);

            if (config('media-processing.log_s3_operations', true)) {
                Log::debug('Temporary file cleaned up', ['file_path' => $absolutePath]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to clean up temporary file', [
                'file_path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upload a local file to a named disk with exponential-backoff retry.
     *
     * @throws VideoProcessingException on final failure
     */
    public function uploadWithRetry(
        string $localFilePath,
        string $permanentPath,
        string $diskName
    ): string {
        $s3Config = config('media-processing.s3_processing');
        $maxRetries = $s3Config['retry_attempts'] ?? 3;
        $retryDelay = $s3Config['retry_delay'] ?? 5;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $fileStream = fopen($localFilePath, 'r');
                if (! $fileStream) {
                    throw new VideoProcessingException("Unable to open local file for upload: {$localFilePath}");
                }

                $startTime = microtime(true);
                try {
                    $success = Storage::disk($diskName)->put($permanentPath, $fileStream);
                } finally {
                    fclose($fileStream);
                }
                $uploadTime = microtime(true) - $startTime;

                if (! $success) {
                    throw new VideoProcessingException("Failed to upload file to storage: {$permanentPath}");
                }

                if (! Storage::disk($diskName)->exists($permanentPath)) {
                    throw new VideoProcessingException("Upload appeared successful but file not found: {$permanentPath}");
                }

                if (config('media-processing.log_s3_operations', true)) {
                    Log::info('File uploaded to permanent storage', [
                        'local_path' => $localFilePath,
                        'permanent_path' => $permanentPath,
                        'disk' => $diskName,
                        'upload_time_seconds' => round($uploadTime, 2),
                        'attempt' => $attempt,
                    ]);
                }

                return $permanentPath;

            } catch (\Exception $e) {
                $isLastAttempt = ($attempt === $maxRetries);

                Log::error('Storage upload attempt failed', [
                    'error' => $e->getMessage(),
                    'local_path' => $localFilePath,
                    'permanent_path' => $permanentPath,
                    'disk' => $diskName,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'is_last_attempt' => $isLastAttempt,
                ]);

                if ($isLastAttempt) {
                    throw new VideoProcessingException(
                        "Failed to upload file after {$maxRetries} attempts. Last error: {$e->getMessage()}",
                        0,
                        $e
                    );
                }

                sleep($retryDelay * $attempt);
            }
        }

        throw new VideoProcessingException('Unexpected end of upload attempts');
    }

    /**
     * Check whether a file exists on the given disk, handling S3 and local disks.
     *
     * For S3 disks, uses the Storage facade's exists() check.
     * For local disks, uses the filesystem path and file_exists().
     */
    public function fileExists(string $filePath, string $diskName): bool
    {
        $disk = Storage::disk($diskName);

        if ($this->isS3CompatibleDisk($disk)) {
            return $disk->exists($filePath);
        }

        return file_exists($filePath);
    }

    /**
     * Return the byte size of a file on the given disk, handling S3 and local disks.
     *
     * Returns 0 if the file cannot be found or the size cannot be determined.
     */
    public function fileSize(string $filePath, string $diskName): int
    {
        $disk = Storage::disk($diskName);

        if ($this->isS3CompatibleDisk($disk)) {
            try {
                return $disk->size($filePath);
            } catch (\Exception) {
                return 0;
            }
        }

        if (! file_exists($filePath)) {
            return 0;
        }

        $size = filesize($filePath);

        return $size === false ? 0 : $size;
    }

    /**
     * Create an FFMpeg instance from config, or return null in the testing environment.
     *
     * Validates that both binaries exist and are executable before creating the instance,
     * so callers get a clear exception rather than a silent hang.
     *
     * @throws VideoProcessingException when FFmpeg cannot be initialized outside of tests.
     */
    public function createFFMpeg(): ?FFMpeg
    {
        if (app()->environment('testing')) {
            Log::debug('Skipping FFmpeg initialization in test environment');

            return null;
        }

        $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path');
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');

        if (! $ffmpegPath || ! file_exists($ffmpegPath) || ! is_executable($ffmpegPath)) {
            throw new VideoProcessingException("FFmpeg binary not found or not executable at: {$ffmpegPath}");
        }

        if (! $ffprobePath || ! file_exists($ffprobePath) || ! is_executable($ffprobePath)) {
            throw new VideoProcessingException("FFprobe binary not found or not executable at: {$ffprobePath}");
        }

        try {
            return FFMpeg::create([
                'ffmpeg.binaries' => $ffmpegPath,
                'ffprobe.binaries' => $ffprobePath,
                'timeout' => 7200,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to initialize FFmpeg', [
                'ffmpeg_path' => $ffmpegPath,
                'ffprobe_path' => $ffprobePath,
                'error' => $e->getMessage(),
            ]);
            throw new VideoProcessingException("Failed to initialize FFmpeg: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Return processing path and permanent path for audio/video output.
     *
     * When the target disk is S3-compatible (driver === 's3'), a local temp path under
     * `{tempDisk}/{tempSubdir}/{filename}` is used for processing, then the caller is
     * expected to upload to the permanent path via uploadWithRetry().
     * When the target disk is local, processing happens directly in place.
     *
     * @return array{processing_path: string, permanent_path: string, use_temp_processing: bool}
     */
    public function getProcessingOutputPath(
        string $filename,
        string $permanentRelativeDir,
        string $permanentDiskName,
        string $tempDiskName,
        string $tempSubdir = 'temp/audio_extraction'
    ): array {
        $permanentPath = $permanentRelativeDir.'/'.$filename;

        if (config("filesystems.disks.{$permanentDiskName}.driver") === 's3') {
            $tempRelative = $tempSubdir.'/'.$filename;
            $tempDisk = Storage::disk($tempDiskName);
            $tempDisk->makeDirectory($tempSubdir);
            $fullTempPath = $tempDisk->path($tempRelative);

            return [
                'processing_path' => $fullTempPath,
                'permanent_path' => $permanentPath,
                'use_temp_processing' => true,
            ];
        }

        $permanentDisk = Storage::disk($permanentDiskName);
        $fullPermanentPath = $permanentDisk->path($permanentPath);
        $dir = dirname($fullPermanentPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return [
            'processing_path' => $fullPermanentPath,
            'permanent_path' => $permanentPath,
            'use_temp_processing' => false,
        ];
    }
}
