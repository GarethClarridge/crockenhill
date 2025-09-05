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
     */
    public function linkVideoToSermon(string $processingId, int $sermonId): void
    {
        /** @var \App\Models\LivestreamProcessingLog $processing */
        $processing = LivestreamProcessingLog::where('processing_id', $processingId)->firstOrFail();
        $sermon = Sermon::findOrFail($sermonId);

        // Get the sermon segment information
        /** @var \App\Models\LivestreamSegment|null $sermonSegment */
        $sermonSegment = $processing->segments()
            ->where('is_sermon_segment', true)
            ->first();

        if (! $sermonSegment) {
            Log::warning('No sermon segment found for processing', [
                'processing_id' => $processingId,
                'sermon_id' => $sermonId,
            ]);

            return;
        }

        // Update sermon record with livestream information
        $sermon->update([
            'livestream_processing_id' => $processingId,
            'video_file_path' => $this->getSermonVideoPath($processingId),
            'source_type' => 'livestream',
            'segment_start_time' => $sermonSegment->start_time,
            'segment_end_time' => $sermonSegment->end_time,
            'livestream_metadata' => [
                'original_filename' => $processing->original_filename,
                'processing_date' => $processing->created_at->toISOString(),
                'total_segments' => $processing->segments()->count(),
                'segment_index' => $sermonSegment->segment_index,
            ],
        ]);

        // Update processing log with sermon link
        $processing->update(['sermon_id' => $sermonId]);

        Log::info('Successfully linked video to sermon', [
            'processing_id' => $processingId,
            'sermon_id' => $sermonId,
            'video_path' => $sermon->video_file_path,
        ]);
    }

    /**
     * Store video with extracted sermon metadata
     *
     * @param  string  $processingId  The livestream processing ID
     * @param  array  $sermonMetadata  Metadata extracted from sermon processing
     * @return string The final video path
     */
    public function storeVideoWithMetadata(string $processingId, array $sermonMetadata): string
    {
        $sermonId = $sermonMetadata['sermon_id'];
        $videoPath = $this->extractSermonVideo($processingId);

        if (! $videoPath) {
            throw new \Exception("No sermon video found for processing ID: {$processingId}");
        }

        // Use metadata from automated sermon processing for organization
        $finalVideoPath = $this->organizeVideoFile($videoPath, [
            'sermon_id' => $sermonId,
            'title' => $sermonMetadata['title'] ?? 'Untitled Sermon',
            'preacher' => $sermonMetadata['preacher'] ?? 'Unknown',
            'date' => $sermonMetadata['date'] ?? now(),
            'series' => $sermonMetadata['series'] ?? null,
            'processing_id' => $processingId,
        ]);

        return $finalVideoPath;
    }

    /**
     * Get the path to the sermon video for a processing ID
     *
     * @param  string  $processingId  The processing ID
     * @return string|null The video path or null if not found
     */
    private function getSermonVideoPath(string $processingId): ?string
    {
        $tempPath = "temp/livestreams/{$processingId}/segments";

        // Look for the sermon video segment
        $files = Storage::files($tempPath);

        foreach ($files as $file) {
            if (str_contains($file, 'sermon.mp4')) {
                return $file;
            }
        }

        return null;
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
            if (file_exists($processing->sermon_video_path)) {
                return $processing->sermon_video_path;
            }

            Log::warning('Sermon video path in processing log does not exist', [
                'processing_id' => $processingId,
                'expected_path' => $processing->sermon_video_path,
            ]);
        }

        // Fallback: Look for sermon video in the old expected location
        $tempPath = "temp/livestreams/{$processingId}/segments";
        $files = Storage::files($tempPath);

        foreach ($files as $file) {
            if (str_contains($file, 'sermon.mp4')) {
                return Storage::path($file);
            }
        }

        Log::warning('No sermon video found in any location', [
            'processing_id' => $processingId,
            'processing_log_path' => $processing?->sermon_video_path,
            'temp_path' => $tempPath,
            'available_files' => $files,
        ]);

        return null;
    }

    /**
     * Organize video file with metadata-based naming and storage
     *
     * @param  string  $videoPath  The source video path
     * @param  array  $metadata  The sermon metadata
     * @return string The final organized path
     */
    private function organizeVideoFile(string $videoPath, array $metadata): string
    {
        // Get the sermon storage disk
        $sermonDisk = Storage::disk(config('livestream-processing.sermon_disk', 'local'));

        // Create directory structure based on sermon ID
        $directory = "sermons/{$metadata['sermon_id']}";
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

        // Store metadata alongside video
        $sermonDisk->put(
            "{$directory}/metadata.json",
            json_encode($metadata, JSON_PRETTY_PRINT)
        );

        Log::info('Video file organized with metadata', [
            'source_path' => $videoPath,
            'final_path' => $finalPath,
            'sermon_id' => $metadata['sermon_id'],
            'title' => $metadata['title'],
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
