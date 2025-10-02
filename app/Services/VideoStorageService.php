<?php

namespace App\Services;

use App\Contracts\VideoStorageServiceInterface;
use App\Data\LivestreamSegment;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoStorageService implements VideoStorageServiceInterface
{
    /** @phpstan-ignore-next-line property.unusedType (nullable for testing environment) */
    private ?FFMpeg $ffmpeg;

    private string $tempDisk;

    private string $permanentDisk;

    private string $videoPath;

    private string $audioPath;

    private VideoExtractionService $videoExtractor;

    public function __construct(VideoExtractionService $videoExtractor)
    {
        // Skip FFmpeg initialization in testing environment to prevent hangs
        if (! app()->environment('testing')) {
            $this->ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
                'timeout' => config('media-processing.processing.timeout'),
            ]);
        }

        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
        $this->permanentDisk = config('media-processing.storage.sermon_disk', 'public');
        $this->videoPath = config('media-processing.storage.paths.video', 'sermons/videos');
        $this->audioPath = config('media-processing.storage.paths.audio', 'sermons/audio');
        $this->videoExtractor = $videoExtractor;
    }

    public function storeUploadedVideo(UploadedFile $file): array
    {
        try {
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $tempPath = 'livestream/temp/'.$filename;

            $storedPath = $file->storeAs('livestream/temp', $filename, $this->tempDisk);
            $fullPath = Storage::disk($this->tempDisk)->path($storedPath);

            Log::info('Video uploaded and stored temporarily', [
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'file_size' => $file->getSize(),
            ]);

            return [
                'temp_path' => $storedPath,
                'full_path' => $fullPath,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to store uploaded video', [
                'error' => $e->getMessage(),
                'original_name' => $file->getClientOriginalName(),
            ]);

            throw $e;
        }
    }

    public function extractVideoSegment(
        string $inputVideoPath,
        LivestreamSegment $segment,
        ?string $outputFilename = null
    ): string {
        return $this->videoExtractor->extractSegmentAsFile($inputVideoPath, $segment, $outputFilename);
    }

    public function extractAudioFromSegment(
        string $inputVideoPath,
        LivestreamSegment $segment,
        ?string $outputFilename = null
    ): string {
        return $this->videoExtractor->extractAudio($inputVideoPath, $segment, [], $outputFilename);
    }

    public function extractOptimizedAudioFromSegment(
        string $inputVideoPath,
        LivestreamSegment $segment,
        ?string $outputFilename = null
    ): array {
        return $this->videoExtractor->extractOptimizedAudio($inputVideoPath, $segment, $outputFilename);
    }

    public function moveToSermonStorage(string $tempVideoPath, string $sermonSlug): array
    {
        try {
            $videoFilename = $sermonSlug.'.mp4';
            $audioFilename = $sermonSlug.'.mp3';

            $videoPath = $this->videoPath.'/'.$videoFilename;
            $audioPath = $this->audioPath.'/'.$audioFilename;

            $isS3Disk = $this->isS3Disk($this->permanentDisk);

            if ($isS3Disk) {
                // For S3 disks, move temp video file to permanent storage via stream
                $tempVideoStream = Storage::disk($this->tempDisk)->readStream($tempVideoPath);
                Storage::disk($this->permanentDisk)->put($videoPath, $tempVideoStream);

                // Create temporary local file for audio extraction
                $tempAudioPath = storage_path('app/temp/'.$audioFilename);
                $this->ensureDirectoryExists(dirname($tempAudioPath));

                // Extract audio from temp video file
                $tempVideoFullPath = Storage::disk($this->tempDisk)->path($tempVideoPath);
                $video = $this->ffmpeg->open($tempVideoFullPath);
                $format = new Mp3;
                $format->setAudioKiloBitrate(128);
                $video->save($format, $tempAudioPath);

                // Upload audio to S3 and clean up local temp file
                $audioStream = fopen($tempAudioPath, 'r');
                Storage::disk($this->permanentDisk)->put($audioPath, $audioStream);
                fclose($audioStream);
                unlink($tempAudioPath);

                Log::info('Files moved to S3 sermon storage', [
                    'video_path' => $videoPath,
                    'audio_path' => $audioPath,
                    'sermon_slug' => $sermonSlug,
                ]);
            } else {
                // For local disks, use original logic
                $fullVideoPath = Storage::disk($this->permanentDisk)->path($videoPath);
                $fullAudioPath = Storage::disk($this->permanentDisk)->path($audioPath);

                $this->ensureDirectoryExists(dirname($fullVideoPath));
                $this->ensureDirectoryExists(dirname($fullAudioPath));

                Storage::disk($this->tempDisk)->move($tempVideoPath, $videoPath);

                $video = $this->ffmpeg->open(Storage::disk($this->permanentDisk)->path($videoPath));
                $format = new Mp3;
                $format->setAudioKiloBitrate(128);
                $video->save($format, $fullAudioPath);

                Log::info('Files moved to local sermon storage', [
                    'video_path' => $videoPath,
                    'audio_path' => $audioPath,
                    'sermon_slug' => $sermonSlug,
                ]);
            }

            return [
                'video_path' => $videoPath,
                'audio_path' => $audioPath,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to move files to sermon storage', [
                'error' => $e->getMessage(),
                'temp_path' => $tempVideoPath,
                'sermon_slug' => $sermonSlug,
            ]);

            throw $e;
        }
    }

    public function cleanupTemporaryFiles(array $filePaths): void
    {
        foreach ($filePaths as $filePath) {
            if (is_string($filePath)) {
                Storage::delete($filePath);
            }
        }

        Log::info('Temporary files cleaned up', ['file_count' => count($filePaths)]);
    }

    public function cleanupExpiredFiles(): int
    {
        $deletedCount = 0;
        $retentionHours = config('media-processing.temp_file_retention_hours');
        $cutoffTime = now()->subHours($retentionHours);

        try {
            $tempDirectory = 'livestream/temp';
            $files = Storage::disk($this->tempDisk)->files($tempDirectory);

            foreach ($files as $file) {
                $fileTime = Storage::disk($this->tempDisk)->lastModified($file);

                if ($fileTime < $cutoffTime->timestamp) {
                    Storage::disk($this->tempDisk)->delete($file);
                    $deletedCount++;
                }
            }

            Log::info('Expired temporary files cleaned up', [
                'deleted_count' => $deletedCount,
                'retention_hours' => $retentionHours,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to cleanup expired files', [
                'error' => $e->getMessage(),
            ]);
        }

        return $deletedCount;
    }

    public function getStorageStats(): array
    {
        try {
            $tempDisk = Storage::disk($this->tempDisk);
            $permanentDisk = Storage::disk($this->permanentDisk);

            $tempFiles = $tempDisk->files('livestream/temp');
            $videoFiles = $permanentDisk->files($this->videoPath);
            $audioFiles = $permanentDisk->files($this->audioPath);

            $tempSize = array_sum(array_map(fn ($file) => $tempDisk->size($file), $tempFiles));
            $videoSize = array_sum(array_map(fn ($file) => $permanentDisk->size($file), $videoFiles));
            $audioSize = array_sum(array_map(fn ($file) => $permanentDisk->size($file), $audioFiles));

            return [
                'temp_files_count' => count($tempFiles),
                'temp_files_size' => $tempSize,
                'video_files_count' => count($videoFiles),
                'video_files_size' => $videoSize,
                'audio_files_count' => count($audioFiles),
                'audio_files_size' => $audioSize,
                'total_size' => $tempSize + $videoSize + $audioSize,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get storage stats', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function validateStorageSpace(int $requiredBytes): bool
    {
        try {
            $available = disk_free_space(Storage::disk($this->tempDisk)->path(''));

            return $available > ($requiredBytes * 2);
        } catch (\Exception $e) {
            Log::warning('Could not validate storage space', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Check if the permanent disk is S3-compatible (DigitalOcean Spaces, AWS S3, etc.)
     */
    private function isS3Disk(string $diskName): bool
    {
        $diskConfig = config("filesystems.disks.{$diskName}");

        return isset($diskConfig['driver']) && $diskConfig['driver'] === 's3';
    }

    private function ensureDirectoryExists(string $directory): void
    {
        // Skip directory creation for S3 disks - they don't support local paths
        // This method should only be called for local storage now
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true)) {
                throw new \Exception("Failed to create directory: {$directory}");
            }
        }
    }

    public function getVideoUrl(string $videoPath): string
    {
        return Storage::disk($this->permanentDisk)->url($videoPath);
    }

    public function getAudioUrl(string $audioPath): string
    {
        return Storage::disk($this->permanentDisk)->url($audioPath);
    }

    public function videoExists(string $videoPath): bool
    {
        return Storage::disk($this->permanentDisk)->exists($videoPath);
    }

    public function audioExists(string $audioPath): bool
    {
        return Storage::disk($this->permanentDisk)->exists($audioPath);
    }

    // Interface implementation methods

    /**
     * Store uploaded file temporarily for processing
     */
    public function storeTemporary(UploadedFile $file): array
    {
        return $this->storeUploadedVideo($file);
    }

    /**
     * Move processed file to permanent storage
     */
    public function moveToPermanent(array $uploadResult): string
    {
        // Extract processing ID or create a slug from the uploaded file
        $processingId = $uploadResult['processing_id'] ?? Str::uuid();
        $sermonSlug = $uploadResult['sermon_slug'] ?? 'sermon-'.$processingId;

        $result = $this->moveToSermonStorage($uploadResult['temp_path'], $sermonSlug);

        return $result['video_path'];
    }

    /**
     * Clean up temporary files and processing artifacts
     */
    public function cleanup(string $processingId): void
    {
        $this->cleanupTemporaryFiles([]);
    }
}
