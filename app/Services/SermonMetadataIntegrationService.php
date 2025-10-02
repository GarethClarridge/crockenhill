<?php

namespace App\Services;

use App\Models\LivestreamProcessingLog;
use App\Models\Sermon;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service for integrating livestream video processing with sermon metadata
 */
class SermonMetadataIntegrationService
{
    /**
     * Link a video file to a sermon record after processing
     *
     * @param  string  $processingId  The livestream processing ID
     * @param  int  $sermonId  The sermon ID from automated processing
     * @param  string  $finalVideoPath  The path to the organized video file
     */
    public function linkVideoToSermon(string $processingId, int $sermonId, string $finalVideoPath): void
    {
        /** @var \App\Models\LivestreamProcessingLog $processing */
        $processing = LivestreamProcessingLog::where('processing_id', $processingId)->firstOrFail();
        $sermon = Sermon::findOrFail($sermonId);

        // Update sermon record with livestream information using data from processing log
        $sermon->update([
            'livestream_processing_id' => $processingId,
            'video_file_path' => $finalVideoPath,
            'source_type' => 'livestream',
            'segment_start_time' => $processing->sermon_start_time,
            'segment_end_time' => $processing->sermon_end_time,
        ]);

        // Update processing log with sermon link
        $processing->update(['sermon_id' => $sermonId]);

        Log::info('Successfully linked video to sermon', [
            'processing_id' => $processingId,
            'sermon_id' => $sermonId,
            'video_path' => $finalVideoPath,
        ]);
    }

    /**
     * Store video from processing to permanent location
     *
     * @param  string  $processingId  The livestream processing ID
     * @param  int  $sermonId  The sermon ID from processing
     * @return string The final video path
     */
    public function storeVideoForSermon(string $processingId, int $sermonId): string
    {
        $videoPath = $this->extractSermonVideo($processingId);

        if (! $videoPath) {
            throw new \Exception("No sermon video found for processing ID: {$processingId}");
        }

        // Simple organization by sermon ID
        $finalVideoPath = $this->organizeVideoFile($videoPath, $sermonId);

        return $finalVideoPath;
    }

    /**
     * Extract the sermon video from temporary storage
     *
     * @param  string  $processingId  The processing ID
     * @return string|null The path to the extracted video or null if not found
     */
    private function extractSermonVideo(string $processingId): ?string
    {
        // First check if the processing log already has the sermon video path
        $processing = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if ($processing && $processing->sermon_video_path) {
            // The path from ExtractSermon job is now a relative path, check temp disk first
            $tempDisk = config('media-processing.storage.temp_disk', 'local');
            if (Storage::disk($tempDisk)->exists($processing->sermon_video_path)) {
                $absolutePath = Storage::disk($tempDisk)->path($processing->sermon_video_path);
                Log::debug('Found sermon video on temp disk', [
                    'processing_id' => $processingId,
                    'relative_path' => $processing->sermon_video_path,
                    'absolute_path' => $absolutePath,
                ]);

                return $absolutePath;
            }

            // Fallback: check if it's already been moved to sermon disk
            $sermonDisk = config('media-processing.storage.sermon_disk', 'public');
            if (Storage::disk($sermonDisk)->exists($processing->sermon_video_path)) {
                $absolutePath = Storage::disk($sermonDisk)->path($processing->sermon_video_path);
                Log::debug('Found sermon video on sermon disk', [
                    'processing_id' => $processingId,
                    'relative_path' => $processing->sermon_video_path,
                    'absolute_path' => $absolutePath,
                ]);

                return $absolutePath;
            }

            Log::warning('Sermon video path in processing log does not exist', [
                'processing_id' => $processingId,
                'relative_path' => $processing->sermon_video_path,
                'checked_temp_disk' => $tempDisk,
                'checked_sermon_disk' => $sermonDisk,
            ]);
        }

        // Fallback: Look for sermon video in temp storage
        $tempDisk = config('media-processing.storage.temp_disk', 'local');
        $tempPath = "temp/livestreams/{$processingId}/segments";

        try {
            $files = Storage::disk($tempDisk)->files($tempPath);

            foreach ($files as $file) {
                if (str_contains($file, 'sermon.mp4')) {
                    $absolutePath = Storage::disk($tempDisk)->path($file);
                    Log::debug('Found sermon video in temp storage', [
                        'processing_id' => $processingId,
                        'relative_path' => $file,
                        'absolute_path' => $absolutePath,
                    ]);

                    return $absolutePath;
                }
            }
        } catch (\Exception $e) {
            Log::debug('Could not search temp storage for sermon video', [
                'processing_id' => $processingId,
                'temp_path' => $tempPath,
                'error' => $e->getMessage(),
            ]);
        }

        Log::warning('No sermon video found in any location', [
            'processing_id' => $processingId,
            'processing_log_path' => $processing?->sermon_video_path,
            'fallback_temp_path' => $tempPath,
        ]);

        return null;
    }

    /**
     * Organize video file to permanent storage location
     *
     * @param  string  $videoPath  The source video path
     * @param  int  $sermonId  The sermon ID
     * @return string The final organized path
     */
    private function organizeVideoFile(string $videoPath, int $sermonId): string
    {
        // Get the sermon storage disk
        $sermonDisk = Storage::disk(config('media-processing.storage.sermon_disk', 'public'));

        // Create directory structure based on sermon ID
        $directory = "sermons/{$sermonId}";
        $filename = 'video.mp4';
        $finalPath = "{$directory}/{$filename}";

        // Ensure the directory exists
        $sermonDisk->makeDirectory($directory);

        // Copy the video file to the final location
        $sermonDisk->putFileAs(
            $directory,
            new File($videoPath),
            $filename
        );

        Log::info('Video file organized', [
            'source_path' => $videoPath,
            'final_path' => $finalPath,
            'sermon_id' => $sermonId,
        ]);

        return $finalPath;
    }

    /**
     * Get video information for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return array Video information
     */
    public function getVideoInfo(int $sermonId): array
    {
        $sermon = Sermon::with('livestreamProcessing.segments')->find($sermonId);

        if (! $sermon) {
            return ['has_video' => false];
        }

        $hasVideo = ! empty($sermon->video_file_path);

        $info = [
            'has_video' => $hasVideo,
            'source_type' => $sermon->source_type,
        ];

        if ($hasVideo) {
            $info['video_url'] = $sermon->getVideoUrlAttribute();
            $info['video_path'] = $sermon->video_file_path;

            // Add livestream-specific information
            if ($sermon->isFromLivestream()) {
                $info['livestream_info'] = $sermon->getLivestreamInfo();
            }
        }

        return $info;
    }

    /**
     * Get video preview data for administrative interface
     *
     * @param  int  $sermonId  The sermon ID
     * @return array Preview data
     */
    public function getVideoPreviewData(int $sermonId): array
    {
        $sermon = Sermon::find($sermonId);

        if (! $sermon || ! $sermon->hasVideo()) {
            return ['has_video' => false];
        }

        $sermonDisk = Storage::disk(config('media-processing.storage.sermon_disk', 'public'));
        $videoPath = $sermon->video_file_path;

        $previewData = [
            'has_video' => true,
            'video_url' => $sermon->getVideoUrlAttribute(),
            'format' => pathinfo($videoPath, PATHINFO_EXTENSION),
        ];

        // Add file size if accessible
        if ($sermonDisk->exists($videoPath)) {
            $previewData['file_size'] = $sermonDisk->size($videoPath);
            $previewData['file_size_formatted'] = $this->formatFileSize($previewData['file_size']);
        }

        // Add duration if available from livestream metadata
        if ($sermon->isFromLivestream()) {
            $duration = $sermon->getSegmentDuration();
            if ($duration) {
                $previewData['duration'] = $duration;
                $previewData['duration_formatted'] = $sermon->getSegmentDurationFormatted();
            }
        }

        return $previewData;
    }

    /**
     * Format file size in human-readable format
     *
     * @param  int  $bytes  File size in bytes
     * @return string Formatted file size
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Clean up temporary video files after successful processing
     *
     * @param  string  $processingId  The processing ID
     */
    public function cleanupTemporaryVideoFiles(string $processingId): void
    {
        $tempDirectory = "temp/livestreams/{$processingId}";

        if (Storage::exists($tempDirectory)) {
            Storage::deleteDirectory($tempDirectory);

            Log::info('Cleaned up temporary video files', [
                'processing_id' => $processingId,
                'temp_directory' => $tempDirectory,
            ]);
        }
    }

    /**
     * Validate video file integrity
     *
     * @param  string  $videoPath  The video file path (local) or relative path (S3)
     * @param  string|null  $disk  Storage disk name for S3-compatible storage
     * @return bool True if video is valid
     */
    public function validateVideoFile(string $videoPath, ?string $disk = null): bool
    {
        // Check if file exists using storage-aware method
        if (! $this->videoFileExists($videoPath, $disk)) {
            Log::warning('Video file does not exist for validation', [
                'video_path' => $videoPath,
                'disk' => $disk,
            ]);

            return false;
        }

        // Basic file size check using storage-aware method
        $fileSize = $this->getVideoFileSize($videoPath, $disk);
        if ($fileSize === 0) {
            Log::warning('Video file has zero size', [
                'video_path' => $videoPath,
                'disk' => $disk,
            ]);

            return false;
        }

        // For S3 files, we need to download temporarily for MIME type checking
        $localPath = $this->ensureLocalPath($videoPath, $disk);

        // Check if it's a valid video file (basic MIME type check)
        try {
            $mimeType = mime_content_type($localPath);
            if ($mimeType === false) {
                Log::warning('Could not determine MIME type for video file', [
                    'video_path' => $videoPath,
                    'disk' => $disk,
                ]);

                $this->cleanupTempFile($localPath, $disk);

                return false;
            }

            $validMimeTypes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];

            $isValid = in_array($mimeType, $validMimeTypes);

            if (! $isValid) {
                Log::warning('Video file has invalid MIME type', [
                    'video_path' => $videoPath,
                    'disk' => $disk,
                    'mime_type' => $mimeType,
                    'valid_mime_types' => $validMimeTypes,
                ]);
            }

            $this->cleanupTempFile($localPath, $disk);

            return $isValid;
        } catch (\Exception $e) {
            Log::error('Exception during video file validation', [
                'video_path' => $videoPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            $this->cleanupTempFile($localPath, $disk);

            return false;
        }
    }

    /**
     * Check if video file exists using storage-aware method
     *
     * @param  string  $videoPath  Path to video file
     * @param  string|null  $disk  Storage disk name
     * @return bool True if file exists
     */
    private function videoFileExists(string $videoPath, ?string $disk = null): bool
    {
        if ($disk) {
            // For named disks (including S3), use Storage::exists()
            return Storage::disk($disk)->exists($videoPath);
        }

        // For absolute local paths, use file_exists()
        return file_exists($videoPath);
    }

    /**
     * Get video file size using storage-aware method
     *
     * @param  string  $videoPath  Path to video file
     * @param  string|null  $disk  Storage disk name
     * @return int File size in bytes
     */
    private function getVideoFileSize(string $videoPath, ?string $disk = null): int
    {
        if ($disk) {
            // For named disks (including S3), use Storage::size()
            return Storage::disk($disk)->size($videoPath);
        }

        // For absolute local paths, use filesize()
        return filesize($videoPath);
    }

    /**
     * Ensure we have a local path for MIME type checking
     * Downloads S3 files temporarily if needed
     *
     * @param  string  $videoPath  Original video path
     * @param  string|null  $disk  Storage disk name
     * @return string Local file path
     */
    private function ensureLocalPath(string $videoPath, ?string $disk = null): string
    {
        if (! $disk) {
            // Already a local absolute path
            return $videoPath;
        }

        $diskInstance = Storage::disk($disk);

        // Check if this is an S3-compatible disk
        if ($this->isS3CompatibleDisk($diskInstance)) {
            // Download the file temporarily for MIME type checking
            $tempDir = 'temp/validation';
            $tempFilename = 'temp_validation_'.Str::uuid().'.'.pathinfo($videoPath, PATHINFO_EXTENSION);
            $tempPath = $tempDir.'/'.$tempFilename;

            Storage::disk('local')->makeDirectory($tempDir);

            $localTempPath = Storage::disk('local')->path($tempPath);

            // Download S3 file to local temp
            $videoContent = $diskInstance->get($videoPath);
            Storage::disk('local')->put($tempPath, $videoContent);

            return $localTempPath;
        }

        // For local disks, get the full path
        return $diskInstance->path($videoPath);
    }

    /**
     * Clean up temporary file if it was created for S3 validation
     *
     * @param  string  $localPath  Local file path
     * @param  string|null  $disk  Original storage disk name
     */
    private function cleanupTempFile(string $localPath, ?string $disk = null): void
    {
        if ($disk && $this->isS3CompatibleDisk(Storage::disk($disk))) {
            // This was a temporary file downloaded from S3, clean it up
            if (str_contains($localPath, 'temp/validation/')) {
                try {
                    $relativePath = str_replace(Storage::disk('local')->path(''), '', $localPath);
                    Storage::disk('local')->delete($relativePath);
                } catch (\Exception $e) {
                    Log::warning('Failed to cleanup temp validation file', [
                        'temp_path' => $localPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Check if a disk uses S3-compatible storage
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     */
    private function isS3CompatibleDisk($disk): bool
    {
        try {
            $adapter = $disk->getAdapter();

            return $adapter instanceof \League\Flysystem\AwsS3V3\AwsS3V3Adapter;
        } catch (\Exception $e) {
            // If we can't determine the adapter type, assume it's not S3
            return false;
        }
    }
}
