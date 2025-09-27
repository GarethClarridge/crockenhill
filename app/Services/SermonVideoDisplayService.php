<?php

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Facades\Storage;

class SermonVideoDisplayService
{
    public function getSermonWithVideo(int $sermonId): array
    {
        /** @var Sermon|null $sermon */
        $sermon = Sermon::with('livestreamProcessing.segments')->find($sermonId);

        if (! $sermon) {
            throw new \Exception("Sermon with ID {$sermonId} not found");
        }

        /** @var \App\Models\LivestreamProcessingLog|null $livestreamProcessing */
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

    public function getVideoPreviewData(int $sermonId): array
    {
        /** @var Sermon|null $sermon */
        $sermon = Sermon::find($sermonId);

        if (! $sermon || ! $sermon->video_file_path) {
            return ['has_video' => false];
        }

        $videoPath = $this->getVideoStoragePath($sermon->video_file_path);

        return [
            'has_video' => true,
            'video_url' => $this->getVideoUrl($sermon->video_file_path),
            'duration' => $this->getVideoDuration($videoPath),
            'file_size' => $this->getVideoFileSize($videoPath),
            'format' => pathinfo($sermon->video_file_path, PATHINFO_EXTENSION),
        ];
    }

    public function getVideoUrl(string $videoPath): string
    {
        $disk = Storage::disk(config('livestream-processing.sermon_disk', 'public'));

        if ($disk->exists($videoPath)) {
            return $disk->url($videoPath);
        }

        return '';
    }

    private function getVideoStoragePath(string $videoPath): string
    {
        $disk = Storage::disk(config('livestream-processing.sermon_disk', 'public'));

        // For S3-compatible disks, we need to download temporarily or use different approach
        if ($this->isS3CompatibleDisk($disk)) {
            // For S3, we can't return a local path. Return the relative path for reference.
            return $videoPath;
        }

        return $disk->path($videoPath);
    }

    private function getVideoDuration(string $videoPath): ?float
    {
        $disk = config('livestream-processing.sermon_disk', 'public');

        // Check if file exists using storage-aware method
        if (! $this->videoFileExists($videoPath, $disk)) {
            return null;
        }

        // Get local path for FFprobe (download from S3 if needed)
        $localPath = $this->ensureLocalVideoPath($videoPath, $disk);

        try {
            $ffprobe = config('livestream-processing.ffprobe_path', '/usr/bin/ffprobe');
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

            // Clean up temporary file if it was downloaded from S3
            $this->cleanupTempFile($localPath, $disk);

            return $duration;
        } catch (\Exception $e) {
            \Log::warning('Failed to get video duration', [
                'video_path' => $videoPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            $this->cleanupTempFile($localPath, $disk);

            return null;
        }
    }

    private function getVideoFileSize(string $videoPath): ?int
    {
        $disk = config('livestream-processing.sermon_disk', 'public');

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

    public function getLivestreamSourceIndicator(Sermon $sermon): array
    {
        if ($sermon->source_type !== 'livestream' || ! $sermon->livestreamProcessing) {
            return ['is_livestream' => false];
        }

        /** @var \App\Models\LivestreamProcessingLog $livestreamProcessing */
        $livestreamProcessing = $sermon->livestreamProcessing;

        return [
            'is_livestream' => true,
            'original_filename' => $livestreamProcessing->original_filename,
            'processing_status' => $livestreamProcessing->status,
            'segment_count' => $livestreamProcessing->segments->count(),
            'processing_date' => $livestreamProcessing->created_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Check if video file exists using storage-aware method
     *
     * @param  string  $videoPath  Path to video file
     * @param  string  $disk  Storage disk name
     * @return bool True if file exists
     */
    private function videoFileExists(string $videoPath, string $disk): bool
    {
        return Storage::disk($disk)->exists($videoPath);
    }

    /**
     * Ensure we have a local path for FFprobe processing
     * Downloads S3 files temporarily if needed
     *
     * @param  string  $videoPath  Original video path
     * @param  string  $disk  Storage disk name
     * @return string Local file path for FFprobe
     */
    private function ensureLocalVideoPath(string $videoPath, string $disk): string
    {
        $diskInstance = Storage::disk($disk);

        // Check if this is an S3-compatible disk
        if ($this->isS3CompatibleDisk($diskInstance)) {
            // Download the file temporarily for FFprobe processing
            $tempDir = 'temp/ffprobe';
            $tempFilename = 'temp_ffprobe_'.uniqid().'.'.pathinfo($videoPath, PATHINFO_EXTENSION);
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
     * Clean up temporary file if it was created for S3 processing
     *
     * @param  string  $localPath  Local file path
     * @param  string  $disk  Original storage disk name
     */
    private function cleanupTempFile(string $localPath, string $disk): void
    {
        $diskInstance = Storage::disk($disk);

        if ($this->isS3CompatibleDisk($diskInstance)) {
            // This was a temporary file downloaded from S3, clean it up
            if (str_contains($localPath, 'temp/ffprobe/')) {
                try {
                    $relativePath = str_replace(Storage::disk('local')->path(''), '', $localPath);
                    Storage::disk('local')->delete($relativePath);
                } catch (\Exception $e) {
                    \Log::warning('Failed to cleanup temp ffprobe file', [
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
