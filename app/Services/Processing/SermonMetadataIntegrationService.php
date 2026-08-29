<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\SermonSourceType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Service for integrating livestream video processing with sermon metadata
 */
class SermonMetadataIntegrationService
{
    public function __construct(
        private readonly StorageAdapterHelper $storageHelper,
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Link a video file to a sermon record after processing
     *
     * @param  string  $processingId  The livestream processing ID
     * @param  int  $sermonId  The sermon ID from automated processing
     * @param  string  $finalVideoPath  The path to the organized video file
     *
     * @throws ModelNotFoundException
     */
    public function linkVideoToSermon(string $processingId, int $sermonId, string $finalVideoPath): void
    {
        /** @var MediaProcessingLog $processing */
        $processing = MediaProcessingLog::query()->where('processing_id', $processingId)->firstOrFail();
        $sermon = Sermon::query()->findOrFail($sermonId);

        // Update sermon record with livestream information using data from processing log
        $sermon->update([
            'livestream_processing_id' => $processingId,
            'video_file_path' => $finalVideoPath,
            'source_type' => SermonSourceType::Livestream,
            'segment_start_time' => $processing->sermon_start_time,
            'segment_end_time' => $processing->sermon_end_time,
        ]);

        // Update processing log with sermon link AND final video path for thumbnail generation
        $processing->update([
            'sermon_id' => $sermonId,
            'video_file_path' => $finalVideoPath,  // Update to permanent path for thumbnail job
        ]);

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
     *
     * @throws \Exception
     * @throws \RuntimeException
     */
    public function storeVideoForSermon(string $processingId, int $sermonId): string
    {
        $videoPath = $this->extractSermonVideo($processingId);

        if (! $videoPath) {
            throw new \Exception("No sermon video found for processing ID: {$processingId}");
        }

        if (! $this->validateVideoFile($videoPath)) {
            throw new \RuntimeException("Sermon video is not a valid upload candidate: {$videoPath}");
        }

        $sourceFileSize = filesize($videoPath);

        Log::info('Preparing sermon video for permanent storage', [
            'processing_id' => $processingId,
            'sermon_id' => $sermonId,
            'source_path' => $videoPath,
            'source_size_bytes' => $sourceFileSize === false ? null : $sourceFileSize,
            'sermon_disk' => config('media-processing.storage.sermon_disk', 'public'),
        ]);

        $processing = MediaProcessingLog::query()->where('processing_id', $processingId)->first();
        $isReExtraction = $processing instanceof MediaProcessingLog && $processing->isReExtraction();

        // Simple organization by sermon ID
        $finalVideoPath = $this->organizeVideoFile($videoPath, $sermonId, $isReExtraction);

        $processing?->clearReExtraction();

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
        $processing = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if ($processing && $processing->video_file_path) {
            // The path from ExtractSermon job is now a relative path, check temp disk first
            $tempDisk = config('media-processing.storage.temp_disk', 'local');
            if (Storage::disk($tempDisk)->exists($processing->video_file_path)) {
                $absolutePath = Storage::disk($tempDisk)->path($processing->video_file_path);
                Log::debug('Found sermon video on temp disk', [
                    'processing_id' => $processingId,
                    'relative_path' => $processing->video_file_path,
                    'absolute_path' => $absolutePath,
                ]);

                return $absolutePath;
            }

            // Fallback: check if it's already been moved to sermon disk
            $sermonDisk = config('media-processing.storage.sermon_disk', 'public');
            if (Storage::disk($sermonDisk)->exists($processing->video_file_path)) {
                $absolutePath = Storage::disk($sermonDisk)->path($processing->video_file_path);
                Log::debug('Found sermon video on sermon disk', [
                    'processing_id' => $processingId,
                    'relative_path' => $processing->video_file_path,
                    'absolute_path' => $absolutePath,
                ]);

                return $absolutePath;
            }

            Log::warning('Sermon video path in processing log does not exist', [
                'processing_id' => $processingId,
                'relative_path' => $processing->video_file_path,
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
            'processing_log_path' => $processing?->video_file_path,
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
    /**
     * Whether the object already at $finalPath is byte-identical to the local file.
     *
     * Hashed incrementally off the stream: a sermon video is routinely hundreds of
     * megabytes, so reading it into a string to hash it would exhaust the worker's
     * memory limit on exactly the re-run this check exists to make safe.
     */
    private function storedVideoMatches(Filesystem $sermonDisk, string $finalPath, string $videoPath): bool
    {
        $sourceHash = hash_file('sha256', $videoPath);

        if ($sourceHash === false) {
            return false;
        }

        $storedStream = $sermonDisk->readStream($finalPath);

        if (! is_resource($storedStream)) {
            throw new \RuntimeException("Unable to verify existing sermon video: {$finalPath}");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $storedStream);
            $storedHash = hash_final($context);
        } finally {
            fclose($storedStream);
        }

        return hash_equals($sourceHash, $storedHash);
    }

    /**
     * @param  bool  $replaceExisting  Whether a stored video with different content may be
     *                                 replaced. Only a deliberate re-cut sets this; see
     *                                 {@see MediaProcessingLog::isReExtraction()}.
     */
    private function organizeVideoFile(string $videoPath, int $sermonId, bool $replaceExisting = false): string
    {
        $sermonDiskName = config('media-processing.storage.sermon_disk', 'public');

        // Get the sermon storage disk
        $sermonDisk = Storage::disk($sermonDiskName);

        // Create directory structure based on sermon ID
        $directory = "sermons/{$sermonId}";
        $finalPath = "{$directory}/video.mp4";

        if ($sermonDisk->exists($finalPath)) {
            if ($this->storedVideoMatches($sermonDisk, $finalPath, $videoPath)) {
                return $finalPath;
            }

            if (! $replaceExisting) {
                throw new \RuntimeException("Refusing to overwrite existing sermon video with different content: {$finalPath}");
            }

            // Re-cutting a sermon means replacing its video. The old file is removed only
            // here, with the replacement already validated and in hand, so the sermon is
            // never left with neither.
            Log::info('Replacing sermon video for a re-extraction', [
                'sermon_id' => $sermonId,
                'final_path' => $finalPath,
            ]);

            $sermonDisk->delete($finalPath);
        }

        // Ensure the directory exists
        $sermonDisk->makeDirectory($directory);

        $stream = fopen($videoPath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException("Unable to open sermon video for upload: {$videoPath}");
        }

        try {
            $uploaded = $sermonDisk->put($finalPath, $stream);
        } finally {
            fclose($stream);
        }

        // put() is the persistence signal; re-probing with exists() here costs an
        // extra round trip and tells us nothing put() did not already report.
        if ($uploaded === false) {
            throw new \RuntimeException("Failed to persist sermon video to {$sermonDiskName}: {$finalPath}");
        }

        $storedFileSize = $sermonDisk->size($finalPath);

        if ($storedFileSize === 0) {
            throw new \RuntimeException("Persisted sermon video has zero size on {$sermonDiskName}: {$finalPath}");
        }

        Log::info('Video file organized', [
            'source_path' => $videoPath,
            'final_path' => $finalPath,
            'sermon_id' => $sermonId,
            'disk' => $sermonDiskName,
            'stored_size_bytes' => $storedFileSize,
        ]);

        return $finalPath;
    }

    /**
     * Get the segment duration for a livestream sermon in seconds
     */
    public function getSegmentDuration(Sermon $sermon): ?float
    {
        if (! $sermon->isFromLivestream() || ! $sermon->segment_start_time || ! $sermon->segment_end_time) {
            return null;
        }

        // A concat cut spans a gap between source segments, so its true media
        // duration is the sum of the extracted spans recorded on the processing
        // log — not segment_end_time - segment_start_time, which would count the
        // gap. Only trust the recorded duration while the plan still describes
        // the Sermon's current bounds: an operator editing segment_start_time /
        // segment_end_time (SaveSermonDetails) makes the plan stale, so fall
        // back to the continuous span there and for single-span/legacy runs.
        $recorded = $sermon->livestreamProcessing?->recordedSermonExtraction();

        if ($recorded !== null
            && $this->boundsMatch($recorded['start'], (float) $sermon->segment_start_time)
            && $this->boundsMatch($recorded['end'], (float) $sermon->segment_end_time)) {
            return $recorded['duration'];
        }

        return $sermon->segment_end_time - $sermon->segment_start_time;
    }

    /**
     * Whether two segment timestamps are the same bound, tolerating the
     * sub-millisecond drift of a decimal(10,3) round-trip.
     */
    private function boundsMatch(float $left, float $right): bool
    {
        return abs($left - $right) < 0.001;
    }

    /**
     * Get formatted segment duration (e.g. "45m 30s")
     */
    public function getSegmentDurationFormatted(Sermon $sermon): ?string
    {
        $duration = $this->getSegmentDuration($sermon);

        if ($duration === null) {
            return null;
        }

        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        return sprintf('%dm %ds', $minutes, $seconds);
    }

    /**
     * Get livestream metadata for a sermon
     *
     * @return array{
     *     processing_id: string|null,
     *     original_filename: string|null,
     *     segment_start_time: float|null,
     *     segment_end_time: float|null,
     *     segment_duration: float|null,
     *     segment_duration_formatted: string|null,
     *     has_video: bool,
     *     video_url: string|null
     * }|array<empty, empty>
     */
    public function getLivestreamInfo(Sermon $sermon): array
    {
        if (! $sermon->isFromLivestream()) {
            return [];
        }

        return [
            'processing_id' => $sermon->livestream_processing_id,
            'original_filename' => optional($sermon->livestreamProcessing)->original_filename,
            'segment_start_time' => $sermon->segment_start_time,
            'segment_end_time' => $sermon->segment_end_time,
            'segment_duration' => $this->getSegmentDuration($sermon),
            'segment_duration_formatted' => $this->getSegmentDurationFormatted($sermon),
            'has_video' => $sermon->hasVideo(),
            'video_url' => $this->sermonViewPresenter->videoUrl($sermon),
        ];
    }

    /**
     * Get video information for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return array{
     *     has_video: bool,
     *     source_type?: SermonSourceType|null,
     *     video_url?: string|null,
     *     video_path?: string|null,
     *     livestream_info?: array{
     *         processing_id: string|null,
     *         original_filename: string|null,
     *         segment_start_time: float|null,
     *         segment_end_time: float|null,
     *         segment_duration: float|null,
     *         segment_duration_formatted: string|null,
     *         has_video: bool,
     *         video_url: string|null
     *     }|array<empty, empty>
     * } Video information
     */
    public function getVideoInfo(int $sermonId): array
    {
        $sermon = Sermon::query()->with('livestreamProcessing.segments')->find($sermonId);

        if (! $sermon) {
            return ['has_video' => false];
        }

        $hasVideo = ! empty($sermon->video_file_path);

        $info = [
            'has_video' => $hasVideo,
            'source_type' => $sermon->source_type,
        ];

        if ($hasVideo) {
            $info['video_url'] = $this->sermonViewPresenter->videoUrl($sermon);
            $info['video_path'] = $sermon->video_file_path;

            // Add livestream-specific information
            if ($sermon->isFromLivestream()) {
                $info['livestream_info'] = $this->getLivestreamInfo($sermon);
            }
        }

        return $info;
    }

    /**
     * Get video preview data for administrative interface
     *
     * @param  int  $sermonId  The sermon ID
     * @return array{
     *     has_video: bool,
     *     video_url?: string|null,
     *     format?: string,
     *     file_size?: int,
     *     file_size_formatted?: string,
     *     duration?: float,
     *     duration_formatted?: string|null
     * } Preview data
     */
    public function getVideoPreviewData(int $sermonId): array
    {
        $sermon = Sermon::query()->find($sermonId);

        if (! $sermon || ! $sermon->hasVideo()) {
            return ['has_video' => false];
        }

        $sermonDisk = Storage::disk(config('media-processing.storage.sermon_disk', 'public'));
        $videoPath = $sermon->video_file_path;

        if (! is_string($videoPath) || $videoPath === '') {
            return ['has_video' => false];
        }

        $previewData = [
            'has_video' => true,
            'video_url' => $this->sermonViewPresenter->videoUrl($sermon),
            'format' => pathinfo($videoPath, PATHINFO_EXTENSION),
        ];

        // Add file size if accessible
        if ($sermonDisk->exists($videoPath)) {
            $previewData['file_size'] = $sermonDisk->size($videoPath);
            $previewData['file_size_formatted'] = $this->formatFileSize($previewData['file_size']);
        }

        // Add duration if available from livestream metadata
        if ($sermon->isFromLivestream()) {
            $duration = $this->getSegmentDuration($sermon);
            if ($duration) {
                $previewData['duration'] = $duration;
                $previewData['duration_formatted'] = $this->getSegmentDurationFormatted($sermon);
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
        return Number::fileSize($bytes, precision: 2);
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
        $localPath = $disk
            ? $this->storageHelper->downloadToTemp($videoPath, $disk, 'local', 'temp/validation')
            : $videoPath;

        $isS3Download = $disk && $localPath !== Storage::disk($disk)->path($videoPath);

        // Check if it's a valid video file (basic MIME type check)
        try {
            $mimeType = mime_content_type($localPath);
            if ($mimeType === false) {
                Log::warning('Could not determine MIME type for video file', [
                    'video_path' => $videoPath,
                    'disk' => $disk,
                ]);

                if ($isS3Download) {
                    $this->storageHelper->cleanupTempFile($localPath);
                }

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

            if ($isS3Download) {
                $this->storageHelper->cleanupTempFile($localPath);
            }

            return $isValid;
        } catch (\Exception $e) {
            Log::error('Exception during video file validation', [
                'video_path' => $videoPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            if ($isS3Download) {
                $this->storageHelper->cleanupTempFile($localPath);
            }

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
        $fileSize = filesize($videoPath);

        return $fileSize === false ? 0 : $fileSize;
    }
}
