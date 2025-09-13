<?php

namespace App\Services;

use App\Models\LivestreamProcessingLog;
use App\Models\Sermon;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
            $tempDisk = config('livestream-processing.temp_disk', 'local');
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
            $sermonDisk = config('livestream-processing.sermon_disk', 'public');
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
        $tempDisk = config('livestream-processing.temp_disk', 'local');
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
        $sermonDisk = Storage::disk(config('livestream-processing.sermon_disk', 'local'));

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

        $sermonDisk = Storage::disk(config('livestream-processing.sermon_disk', 'local'));
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
     * @param  string  $videoPath  The video file path
     * @return bool True if video is valid
     */
    public function validateVideoFile(string $videoPath): bool
    {
        if (! file_exists($videoPath)) {
            return false;
        }

        // Basic file size check
        $fileSize = filesize($videoPath);
        if ($fileSize === 0) {
            return false;
        }

        // Check if it's a valid video file (basic MIME type check)
        $mimeType = mime_content_type($videoPath);
        $validMimeTypes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];

        return in_array($mimeType, $validMimeTypes);
    }
}
