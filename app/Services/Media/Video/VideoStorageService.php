<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\LivestreamSegment;
use App\Exceptions\VideoProcessingException;
use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Traits\DetectsStorageType;
use App\Traits\RequiresFfmpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoStorageService
{
    use DetectsStorageType;
    use RequiresFfmpeg;

    private string $tempDisk;

    private string $permanentDisk;

    private string $videoPath;

    private string $audioPath;

    public function __construct(
        private readonly VideoExtractionService $videoExtractor,
        private readonly AudioCompressionService $audioCompressor,
        private readonly StorageAdapterHelper $storageHelper
    ) {
        $this->ffmpeg = $storageHelper->createFFMpeg();

        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
        $this->permanentDisk = config('media-processing.storage.sermon_disk', 'public');
        $this->videoPath = config('media-processing.storage.paths.video', 'sermons/videos');
        $this->audioPath = config('media-processing.storage.paths.audio', 'sermons/audio');
    }

    /**
     * Store an uploaded video file temporarily for processing.
     *
     * @param  UploadedFile  $file  The uploaded video file
     * @return array{
     *     temp_path: string,
     *     full_path: string,
     *     original_filename: string,
     *     file_size: int,
     *     mime_type: string|null
     * }
     *
     * @throws \RuntimeException If the file cannot be stored on the temp disk
     */
    public function storeUploadedVideo(UploadedFile $file): array
    {
        try {
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $tempPath = 'livestream/temp/'.$filename;

            $storedPath = $file->storeAs('livestream/temp', $filename, $this->tempDisk);
            if ($storedPath === false) {
                throw new \RuntimeException('Failed to store uploaded video on temp disk.');
            }

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

    /**
     * Extract a video segment from a source video file.
     *
     * @param  string  $inputVideoPath  Absolute path to the source video
     * @param  LivestreamSegment  $segment  The segment to extract
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return string The relative path to the extracted video file
     */
    public function extractVideoSegment(
        string $inputVideoPath,
        LivestreamSegment $segment,
        ?string $outputFilename = null
    ): string {
        return $this->videoExtractor->extractSegmentAsFile($inputVideoPath, $segment, $outputFilename);
    }

    /**
     * Extract audio from a specific video segment.
     *
     * @param  string  $inputVideoPath  Absolute path to the source video
     * @param  LivestreamSegment  $segment  The segment to extract audio from
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return string The storage path to the extracted audio file
     */
    public function extractAudioFromSegment(
        string $inputVideoPath,
        LivestreamSegment $segment,
        ?string $outputFilename = null
    ): string {
        return $this->videoExtractor->extractAudio($inputVideoPath, $segment, [], $outputFilename);
    }

    /**
     * @return array{
     *     audio_path: string,
     *     full_path: string,
     *     original_size: int,
     *     final_size: int,
     *     compression_applied: bool,
     *     compression_ratio: float,
     *     valid_for_transcription: bool
     * }
     */
    public function extractOptimizedAudioFromSegment(
        string $inputVideoPath,
        LivestreamSegment $segment,
        ?string $outputFilename = null
    ): array {
        return $this->audioCompressor->extractOptimizedAudio(
            $inputVideoPath,
            $segment,
            $outputFilename,
            null,
            null,
            fn (string $localFilePath, string $permanentPath): string => $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $this->permanentDisk)
        );
    }

    /**
     * Move processed media files from temporary to permanent sermon storage.
     *
     * Handles both local and S3-compatible storage logic, including
     * audio extraction and S3 stream uploads where necessary.
     *
     * @param  string  $tempVideoPath  The relative path on the temp disk
     * @param  string  $sermonSlug  The slug to use for the final filenames
     * @return array{video_path: string, audio_path: string} Final storage paths
     *
     * @throws \RuntimeException If file operations or S3 uploads fail
     * @throws \Exception For underlying filesystem errors
     */
    public function moveToSermonStorage(string $tempVideoPath, string $sermonSlug): array
    {
        try {
            $videoFilename = $sermonSlug.'.mp4';
            $audioFilename = $sermonSlug.'.mp3';

            $videoPath = $this->videoPath.'/'.$videoFilename;
            $audioPath = $this->audioPath.'/'.$audioFilename;

            $isS3Disk = $this->isS3Disk($this->permanentDisk);

            if ($isS3Disk) {
                $this->copyTempVideoToPermanentDisk($tempVideoPath, $videoPath);

                // Create temporary local file for audio extraction
                $tempAudioPath = storage_path('app/temp/'.$audioFilename);
                $this->ensureDirectoryExists(dirname($tempAudioPath));

                // Extract audio from temp video file
                $tempVideoFullPath = Storage::disk($this->tempDisk)->path($tempVideoPath);
                $video = $this->requireFfmpeg()->open($tempVideoFullPath);
                $format = new Mp3;
                $format->setAudioKiloBitrate(128);
                $video->save($format, $tempAudioPath);

                // Upload audio to S3 and clean up local temp file
                $audioStream = fopen($tempAudioPath, 'r');
                if ($audioStream === false) {
                    throw new \RuntimeException("Failed to open temporary audio file: {$tempAudioPath}");
                }

                $audioStored = Storage::disk($this->permanentDisk)->put($audioPath, $audioStream);
                fclose($audioStream);

                if ($audioStored === false) {
                    throw new \RuntimeException("Failed to store extracted audio on disk [{$this->permanentDisk}]: {$audioPath}");
                }

                if (file_exists($tempAudioPath)) {
                    unlink($tempAudioPath);
                }

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

                $this->moveTempVideoToPermanentDisk($tempVideoPath, $videoPath);

                $video = $this->requireFfmpeg()->open(Storage::disk($this->permanentDisk)->path($videoPath));
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

    /**
     * @param  array<int, string>  $filePaths
     */
    public function cleanupTemporaryFiles(array $filePaths): void
    {
        $deletedCount = 0;

        // Clean up specific files passed in the array
        foreach ($filePaths as $filePath) {
            if ($filePath === '') {
                continue;
            }

            try {
                // Try temp disk first
                if (Storage::disk($this->tempDisk)->exists($filePath)) {
                    Storage::disk($this->tempDisk)->delete($filePath);
                    $deletedCount++;
                    Log::debug('Deleted temp file', ['file' => $filePath, 'disk' => $this->tempDisk]);
                }
                // Check if it's an absolute path (for local files)
                elseif (file_exists($filePath)) {
                    unlink($filePath);
                    $deletedCount++;
                    Log::debug('Deleted local temp file', ['file' => $filePath]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to delete temp file', [
                    'file' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Temporary files cleaned up', [
            'file_count' => count($filePaths),
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Retrieve aggregate statistics for video and audio storage disks.
     *
     * @return array{
     *     temp_files_count: int,
     *     temp_files_size: int,
     *     video_files_count: int,
     *     video_files_size: int,
     *     audio_files_count: int,
     *     audio_files_size: int,
     *     total_size: int
     * }|array<never, never>
     */
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

    /**
     * Verify if enough local disk space is available for processing.
     *
     * @param  int  $requiredBytes  The estimated storage needed
     * @return bool True if sufficient space exists
     */
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
     * Upload a local file to permanent storage with exponential-backoff retry.
     *
     * @param  string  $localFilePath  Absolute path to the local source file
     * @param  string  $permanentPath  Relative destination path on the permanent disk
     * @return string The confirmed permanent storage path
     *
     * @throws VideoProcessingException If all upload attempts fail
     */
    public function uploadToPermanentStorage(string $localFilePath, string $permanentPath): string
    {
        return $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $this->permanentDisk);
    }

    private function moveTempVideoToPermanentDisk(string $tempVideoPath, string $videoPath): void
    {
        if ($this->tempDisk === $this->permanentDisk) {
            $moved = Storage::disk($this->tempDisk)->move($tempVideoPath, $videoPath);

            if ($moved === false) {
                throw new \RuntimeException("Failed to move temp video on disk [{$this->tempDisk}]: {$tempVideoPath}");
            }

            return;
        }

        $this->copyTempVideoToPermanentDisk($tempVideoPath, $videoPath);

        $deleted = Storage::disk($this->tempDisk)->delete($tempVideoPath);

        if ($deleted === false) {
            throw new \RuntimeException("Failed to delete temp video on disk [{$this->tempDisk}]: {$tempVideoPath}");
        }
    }

    private function copyTempVideoToPermanentDisk(string $tempVideoPath, string $videoPath): void
    {
        $tempVideoStream = Storage::disk($this->tempDisk)->readStream($tempVideoPath);

        if (! is_resource($tempVideoStream)) {
            throw new \RuntimeException("Failed to read temp video stream: {$tempVideoPath}");
        }

        try {
            $stored = Storage::disk($this->permanentDisk)->put($videoPath, $tempVideoStream);
        } finally {
            fclose($tempVideoStream);
        }

        if ($stored === false) {
            throw new \RuntimeException("Failed to store temp video on disk [{$this->permanentDisk}]: {$videoPath}");
        }
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

    /**
     * Get the public URL for a video file.
     *
     * @param  string  $videoPath  The storage path to the video
     * @return string The full public URL
     */
    public function getVideoUrl(string $videoPath): string
    {
        return Storage::disk($this->permanentDisk)->url($videoPath);
    }

    /**
     * Check if a video file exists in permanent storage.
     *
     * @param  string  $videoPath  The storage path to check
     * @return bool True if the file exists
     */
    public function videoExists(string $videoPath): bool
    {
        return Storage::disk($this->permanentDisk)->exists($videoPath);
    }

    /**
     * Check whether the source video for a processing run is still accessible.
     *
     * Verifies existence either on the configured temp disk or as an
     * absolute filesystem path.
     *
     * @param  string  $sourceFilePath  The path to check
     * @return bool True if the source video exists
     */
    public function sourceVideoExistsForPath(string $sourceFilePath): bool
    {
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        return Storage::disk($tempDisk)->exists($sourceFilePath)
            || file_exists($sourceFilePath);
    }

    /**
     * Check if an audio file exists in permanent storage.
     *
     * @param  string  $audioPath  The storage path to check
     * @return bool True if the file exists
     */
    public function audioExists(string $audioPath): bool
    {
        return Storage::disk($this->permanentDisk)->exists($audioPath);
    }

    /**
     * Clean up temporary files and processing artifacts.
     *
     * Interface implementation; currently a no-op as artifacts are
     * handled by the specialized cleanupTemporaryFiles method.
     *
     * @param  string  $processingId  The processing identifier to clean up
     */
    public function cleanup(string $processingId): void
    {
        $this->cleanupTemporaryFiles([]);
    }
}
