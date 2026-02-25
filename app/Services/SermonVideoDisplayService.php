<?php

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Facades\Storage;

class SermonVideoDisplayService
{
    public function __construct(
        private readonly StorageAdapterHelper $storageHelper
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSermonWithVideo(int $sermonId): array
    {
        /** @var Sermon|null $sermon */
        $sermon = Sermon::with('livestreamProcessing.segments')->find($sermonId);

        if (! $sermon) {
            throw new \Exception("Sermon with ID {$sermonId} not found");
        }

        /** @var \App\Models\MediaProcessingLog|null $livestreamProcessing */
        $livestreamProcessing = $sermon->livestreamProcessing;

        return [
            'sermon' => $sermon,
            'has_video' => ! empty($sermon->video_file_path),
            'video_url' => $sermon->video_file_path ? $this->getVideoUrl($sermon->video_file_path) : null,
            'source_type' => $sermon->source_type,
            'livestream_info' => $livestreamProcessing ? [
                'original_filename' => $livestreamProcessing->original_filename,
                'processing_date' => $livestreamProcessing->created_at,
                'segment_start' => $sermon->segment_start_time,
                'segment_end' => $sermon->segment_end_time,
                'total_segments' => $livestreamProcessing->segments->count(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getVideoPreviewData(int $sermonId): array
    {
        /** @var Sermon|null $sermon */
        $sermon = Sermon::find($sermonId);

        if (! $sermon || ! $sermon->video_file_path) {
            return ['has_video' => false];
        }

        $videoPath = $this->getVideoStoragePath($sermon->video_file_path);

        /**
         * Performance Optimization: Use pre-populated duration from DB if available.
         * For livestream sermons, use calculated segment duration.
         * Only fall back to expensive FFprobe (which may download from S3) if necessary.
         */
        $duration = $sermon->duration;
        if (! $duration && $sermon->isFromLivestream()) {
            $duration = $sermon->getSegmentDuration();
        }

        return [
            'has_video' => true,
            'video_url' => $this->getVideoUrl($sermon->video_file_path),
            'duration' => $duration ?: $this->getVideoDuration($videoPath, $sermon),
            'file_size' => $this->getVideoFileSize($videoPath),
            'format' => pathinfo($sermon->video_file_path, PATHINFO_EXTENSION),
        ];
    }

    public function getVideoUrl(string $videoPath): string
    {
        $disk = Storage::disk(config('media-processing.storage.sermon_disk', 'public'));

        if ($disk->exists($videoPath)) {
            return $disk->url($videoPath);
        }

        return '';
    }

    private function getVideoStoragePath(string $videoPath): string
    {
        $disk = Storage::disk(config('media-processing.storage.sermon_disk', 'public'));

        if ($this->storageHelper->isS3CompatibleDisk($disk)) {
            return $videoPath;
        }

        return $disk->path($videoPath);
    }

    /**
     * Get video duration using FFprobe as a fallback
     *
     * @param  string  $videoPath  Path to video file
     * @param  Sermon|null  $sermon  Optional sermon model for duration lookup
     * @return float|null Duration in seconds
     */
    private function getVideoDuration(string $videoPath, ?Sermon $sermon = null): ?float
    {
        /**
         * Performance Optimization: Secondary check for duration in DB/metadata
         * to avoid downloading from S3 if possible.
         */
        if ($sermon) {
            if ($sermon->duration) {
                return $sermon->duration;
            }
            if ($sermon->isFromLivestream()) {
                $duration = $sermon->getSegmentDuration();
                if ($duration) {
                    return $duration;
                }
            }
        }

        $disk = config('media-processing.storage.sermon_disk', 'public');

        // Check if file exists using storage-aware method
        if (! $this->videoFileExists($videoPath, $disk)) {
            return null;
        }

        // Get local path for FFprobe (download from S3 if needed)
        $localPath = $this->storageHelper->downloadToTemp($videoPath, $disk, 'local', 'temp/ffprobe');

        try {
            $ffprobe = config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
            $command = [
                $ffprobe,
                '-v', 'quiet',
                '-show_entries', 'format=duration',
                '-of', 'csv=p=0',
                $localPath,
            ];

            $process = new \Symfony\Component\Process\Process($command);
            $process->run();

            $duration = null;
            if ($process->isSuccessful()) {
                $duration = (float) trim($process->getOutput());
            }

            $this->storageHelper->cleanupTempFile($localPath);

            return $duration;
        } catch (\Exception $e) {
            \Log::warning('Failed to get video duration', [
                'video_path' => $videoPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            $this->storageHelper->cleanupTempFile($localPath);

            return null;
        }
    }

    private function getVideoFileSize(string $videoPath): ?int
    {
        $disk = config('media-processing.storage.sermon_disk', 'public');

        // Check if file exists and get size using storage-aware method
        if (! $this->videoFileExists($videoPath, $disk)) {
            return null;
        }

        $diskInstance = Storage::disk($disk);

        try {
            return $diskInstance->size($videoPath);
        } catch (\Exception $e) {
            \Log::warning('Failed to get video file size', [
                'video_path' => $videoPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSermonsBySourceType(?string $sourceType = null): array
    {
        $query = Sermon::with('livestreamProcessing');

        if ($sourceType) {
            $query->where('source_type', $sourceType);
        }

        return $query->orderBy('created_at', 'desc')->get()->map(function (Sermon $sermon) {
            return [
                'id' => $sermon->id,
                'title' => $sermon->title,
                'preacher' => $sermon->preacher,
                'date' => $sermon->date,
                'source_type' => $sermon->source_type,
                'has_video' => ! empty($sermon->video_file_path),
                'livestream_processing_id' => $sermon->livestream_processing_id,
            ];
        })->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getLivestreamSourceIndicator(Sermon $sermon): array
    {
        if ($sermon->source_type !== 'livestream' || ! $sermon->livestreamProcessing) {
            return ['is_livestream' => false];
        }

        /** @var \App\Models\MediaProcessingLog $livestreamProcessing */
        $livestreamProcessing = $sermon->livestreamProcessing;

        return [
            'is_livestream' => true,
            'original_filename' => $livestreamProcessing->original_filename,
            'processing_status' => $livestreamProcessing->status->value,
            'segment_count' => $livestreamProcessing->segments->count(),
            'processing_date' => $livestreamProcessing->created_at->format('Y-m-d H:i:s'),
        ];
    }

    private function videoFileExists(string $videoPath, string $disk): bool
    {
        return Storage::disk($disk)->exists($videoPath);
    }
}
