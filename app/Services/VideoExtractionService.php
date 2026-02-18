<?php

namespace App\Services;

use App\Exceptions\VideoProcessingException;
use App\Traits\DetectsStorageType;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Format\Video\X264;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoExtractionService
{
    use DetectsStorageType;

    private ?FFMpeg $ffmpeg;

    private string $tempDisk;

    private string $permanentDisk;

    private string $audioPath;

    public function __construct(
        private readonly AudioCompressionService $audioCompressor
    ) {
        $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path');
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');

        // Validate FFmpeg binary exists and is executable (skip in testing environment)
        if (! app()->environment('testing')) {
            if (! $ffmpegPath || ! file_exists($ffmpegPath) || ! is_executable($ffmpegPath)) {
                throw new VideoProcessingException("FFmpeg binary not found or not executable at: {$ffmpegPath}");
            }

            if (! $ffprobePath || ! file_exists($ffprobePath) || ! is_executable($ffprobePath)) {
                throw new VideoProcessingException("FFprobe binary not found or not executable at: {$ffprobePath}");
            }
        }

        // Skip FFmpeg initialization in testing environment to prevent hangs
        if (app()->environment('testing')) {
            Log::debug('Skipping FFmpeg initialization in test environment');
            $this->ffmpeg = null;
        } else {
            try {
                $this->ffmpeg = FFMpeg::create([
                    'ffmpeg.binaries' => $ffmpegPath,
                    'ffprobe.binaries' => $ffprobePath,
                    'timeout' => config('media-processing.processing.timeout'),
                ]);

                Log::debug('FFmpeg initialized successfully', [
                    'ffmpeg_path' => $ffmpegPath,
                    'ffprobe_path' => $ffprobePath,
                    'timeout' => config('media-processing.processing.timeout'),
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

        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
        $this->permanentDisk = config('media-processing.storage.sermon_disk', 'public');
        $this->audioPath = config('media-processing.storage.paths.audio', 'sermons/audio');
    }

    /**
     * Extract video segment with stream copy (no re-encoding) - primary method
     *
     * @param  string  $inputPath  Path to the original video file
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  array  $options  Extraction options
     * @return string|UploadedFile Based on options['return_type']
     */
    public function extractSegment(string $inputPath, object $segment, array $options = []): string|UploadedFile
    {
        $returnType = $options['return_type'] ?? 'file_path';
        $outputFilename = $options['output_filename'] ?? null;

        if ($returnType === 'uploaded_file') {
            return $this->extractSegmentAsUpload($inputPath, $segment, $outputFilename);
        }

        return $this->extractSegmentAsFile($inputPath, $segment, $outputFilename);
    }

    /**
     * Extract video segment and return as file path (for storage operations)
     */
    public function extractSegmentAsFile(string $inputPath, object $segment, ?string $outputFilename = null): string
    {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        try {
            // Use Laravel storage disk for consistency with other file operations
            $tempDisk = config('media-processing.storage.temp_disk', 'local');
            $relativePath = 'temp/'.Str::uuid().'.mp4';
            $tempPath = \Illuminate\Support\Facades\Storage::disk($tempDisk)->path($relativePath);

            // Ensure temp directory exists using storage disk
            \Illuminate\Support\Facades\Storage::disk($tempDisk)->makeDirectory(dirname($relativePath));

            $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path');
            $duration = $endTime - $startTime;

            // Use stream copy for maximum speed and quality preservation
            $command = [
                $ffmpegPath,
                '-i', escapeshellarg($inputPath),
                '-ss', (string) $startTime,
                '-t', (string) $duration,
                '-c', 'copy',  // Stream copy - no re-encoding
                '-avoid_negative_ts', 'make_zero',  // Handle timestamp issues
                escapeshellarg($tempPath),
            ];

            $commandString = implode(' ', $command);
            Log::info('Executing FFmpeg stream copy command', [
                'command' => $commandString,
                'start_time' => $startTime,
                'duration' => $duration,
            ]);

            exec($commandString.' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                Log::warning('Stream copy failed, attempting fallback to re-encoding', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output),
                ]);

                return $this->extractSegmentWithReencoding($inputPath, $segment, $outputFilename);
            }

            // Check if file was created - use appropriate method for temp disk
            if (! $this->fileExists($tempPath, $tempDisk)) {
                throw new VideoProcessingException('Output file was not created: '.$tempPath);
            }

            Log::info('Video segment extracted with stream copy (original quality)', [
                'input_path' => $inputPath,
                'output_path' => $tempPath,
                'relative_path' => $relativePath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'output_size' => $this->getFileSize($tempPath, $tempDisk),
            ]);

            return $relativePath;

        } catch (\Exception $e) {
            Log::error('Failed to extract video segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            throw $e;
        }
    }

    /**
     * Extract video segment and return as UploadedFile (for processing pipelines)
     */
    public function extractSegmentAsUpload(string $inputPath, object $segment, ?string $outputFilename = null): UploadedFile
    {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        if ($startTime >= $endTime) {
            throw new VideoProcessingException('Invalid segment times: start time must be less than end time');
        }

        $duration = $endTime - $startTime;

        Log::info('Extracting video segment as UploadedFile', [
            'input_path' => $inputPath,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
        ]);

        // Generate unique filename for extracted segment
        $segmentFilename = 'sermon_segment_'.Str::uuid().'.mp4';
        $segmentPath = 'temp/'.$segmentFilename;
        $fullSegmentPath = Storage::disk($this->tempDisk)->path($segmentPath);

        // Ensure temp directory exists
        $directory = dirname($fullSegmentPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        try {
            // Try stream copy first
            $extractedPath = $this->extractSegmentAsFile($inputPath, $segment, $outputFilename);

            // Move to expected location if needed
            if ($extractedPath !== $fullSegmentPath) {
                rename($extractedPath, $fullSegmentPath);
            }

            Log::info('Video segment extracted successfully', [
                'original_video' => $inputPath,
                'extracted_segment' => $fullSegmentPath,
                'segment_size' => filesize($fullSegmentPath),
                'duration' => $duration,
            ]);

            // Create UploadedFile from the extracted segment
            $originalBasename = pathinfo($inputPath, PATHINFO_FILENAME);
            $uploadedFile = new UploadedFile(
                $fullSegmentPath,
                $originalBasename.'_sermon_segment.mp4',
                'video/mp4',
                null,
                true // Mark as test file to skip validation
            );

            return $uploadedFile;

        } catch (\Exception $e) {
            // Clean up partial file if extraction failed
            if (file_exists($fullSegmentPath)) {
                unlink($fullSegmentPath);
            }

            Log::error('Failed to extract video segment as UploadedFile', [
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw new VideoProcessingException('Failed to extract video segment: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Fallback method using re-encoding when stream copy fails
     */
    private function extractSegmentWithReencoding(string $inputPath, object $segment, ?string $outputFilename = null): string
    {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;
        $duration = $endTime - $startTime;

        Log::info('Using re-encoding fallback for video extraction', [
            'input_path' => $inputPath,
            'start_time' => $startTime,
            'duration' => $duration,
        ]);

        $tempPath = storage_path('app/temp/'.Str::uuid().'.mp4');

        try {
            $video = $this->ffmpeg->open($inputPath);

            // Apply time filters to extract the specific segment
            $video
                ->filters()
                ->clip(TimeCode::fromSeconds($startTime), TimeCode::fromSeconds($duration));

            // Configure video format
            $format = new X264;
            $format->setAudioCodec('aac');

            // Save the extracted segment
            $video->save($format, $tempPath);

            Log::info('Video segment extracted with re-encoding (fallback)', [
                'input_path' => $inputPath,
                'output_path' => $tempPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'output_size' => filesize($tempPath),
            ]);

            return $tempPath;

        } catch (\Exception $e) {
            Log::error('Re-encoding fallback also failed', [
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            throw $e;
        }
    }

    /**
     * Extract audio from video segment
     */
    public function extractAudio(
        string $inputVideoPath,
        object $segment,
        array $compressionOptions = [],
        ?string $outputFilename = null
    ): string {
        try {
            $startTime = $segment->startTime ?? $segment->start_time ?? 0;
            $endTime = $segment->endTime ?? $segment->end_time ?? 0;
            $duration = $endTime - $startTime;

            $outputFilename = $outputFilename ?: Str::uuid().'_sermon.mp3';

            // Get processing paths based on storage type
            $pathInfo = $this->getProcessingOutputPath($outputFilename);
            $processingPath = $pathInfo['processing_path'];
            $permanentPath = $pathInfo['permanent_path'];
            $useS3Processing = $pathInfo['use_temp_processing'];

            /** @var \FFMpeg\Media\Video $video */
            $video = $this->ffmpeg->open($inputVideoPath);

            $format = new Mp3;

            // Apply compression options if provided
            if (! empty($compressionOptions)) {
                $format->setAudioKiloBitrate($compressionOptions['bitrate'] ?? 128);
                if (isset($compressionOptions['channels'])) {
                    $format->setAudioChannels($compressionOptions['channels']);
                }
            } else {
                $format->setAudioKiloBitrate(128);
            }

            $startTimeCode = TimeCode::fromSeconds($startTime);
            $durationTimeCode = TimeCode::fromSeconds($duration);

            $video->clip($startTimeCode, $durationTimeCode)->save($format, $processingPath);

            // Handle S3 upload if needed
            if ($useS3Processing) {
                $this->uploadToPermanentStorage($processingPath, $permanentPath);
                // Clean up temporary file
                $this->cleanupTemporaryFile($processingPath);
                Log::info('Audio extracted and uploaded to S3', [
                    'input_path' => $inputVideoPath,
                    'permanent_path' => $permanentPath,
                    'start_time' => $startTime,
                    'duration' => $duration,
                    'compression_options' => $compressionOptions,
                ]);
            } else {
                Log::info('Audio extracted from video segment', [
                    'input_path' => $inputVideoPath,
                    'output_path' => $permanentPath,
                    'start_time' => $startTime,
                    'duration' => $duration,
                    'compression_options' => $compressionOptions,
                ]);
            }

            return $permanentPath;

        } catch (\Exception $e) {
            Log::error('Failed to extract audio from video segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputVideoPath,
                'segment_start' => $segment->startTime ?? $segment->start_time,
                'compression_options' => $compressionOptions,
            ]);

            throw $e;
        }
    }

    /**
     * Extract optimized audio from segment with compression validation.
     * Delegates to AudioCompressionService; passes its own S3 upload handler.
     */
    public function extractOptimizedAudio(
        string $inputVideoPath,
        object $segment,
        ?string $outputFilename = null
    ): array {
        return $this->audioCompressor->extractOptimizedAudio(
            $inputVideoPath,
            $segment,
            $outputFilename,
            fn (string $localFilePath, string $permanentPath): string => $this->uploadToPermanentStorage($localFilePath, $permanentPath)
        );
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        // Skip directory creation for S3 disks - they don't support local paths
        // This method should only be called for local/temp storage now
        try {
            if (! is_dir($directory)) {
                if (! mkdir($directory, 0755, true)) {
                    throw new VideoProcessingException("Failed to create directory: {$directory}");
                }

                Log::info('Created directory for audio extraction', [
                    'directory' => $directory,
                    'permissions' => '0755',
                ]);
            }

            if (! is_writable($directory)) {
                throw new VideoProcessingException("Directory is not writable: {$directory}");
            }
        } catch (\Exception $e) {
            Log::error('Directory creation failed', [
                'directory' => $directory,
                'error' => $e->getMessage(),
                'permanent_disk' => $this->permanentDisk,
                'is_s3_disk' => $this->isS3Disk($this->permanentDisk),
            ]);
            throw new VideoProcessingException("Directory operation failed for: {$directory}. Error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Upload a local file to permanent S3 storage and return the permanent path
     */
    private function uploadToPermanentStorage(string $localFilePath, string $permanentPath): string
    {
        $s3Config = config('media-processing.s3_processing');
        $maxRetries = $s3Config['retry_attempts'] ?? 3;
        $retryDelay = $s3Config['retry_delay'] ?? 5;
        $uploadTimeout = $s3Config['upload_timeout'] ?? 300;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $fileStream = fopen($localFilePath, 'r');
                if (! $fileStream) {
                    throw new VideoProcessingException("Unable to open local file for S3 upload: {$localFilePath}");
                }

                $startTime = microtime(true);
                $success = Storage::disk($this->permanentDisk)->put($permanentPath, $fileStream);
                $uploadTime = microtime(true) - $startTime;
                fclose($fileStream);

                if (! $success) {
                    throw new VideoProcessingException("Failed to upload file to permanent storage: {$permanentPath}");
                }

                // Verify file was uploaded successfully
                if (! Storage::disk($this->permanentDisk)->exists($permanentPath)) {
                    throw new VideoProcessingException("File upload appeared successful but file not found in storage: {$permanentPath}");
                }

                if (config('media-processing.log_s3_operations', true)) {
                    Log::info('File uploaded to permanent S3 storage', [
                        'local_path' => $localFilePath,
                        'permanent_path' => $permanentPath,
                        'permanent_disk' => $this->permanentDisk,
                        'file_size' => $this->getLocalFileSize($localFilePath),
                        'upload_time_seconds' => round($uploadTime, 2),
                        'attempt' => $attempt,
                    ]);
                }

                return $permanentPath;

            } catch (\Exception $e) {
                $isLastAttempt = ($attempt === $maxRetries);

                Log::error('S3 upload attempt failed', [
                    'error' => $e->getMessage(),
                    'local_path' => $localFilePath,
                    'permanent_path' => $permanentPath,
                    'permanent_disk' => $this->permanentDisk,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'is_last_attempt' => $isLastAttempt,
                ]);

                if ($isLastAttempt) {
                    throw new VideoProcessingException("Failed to upload file to S3 after {$maxRetries} attempts. Last error: {$e->getMessage()}", 0, $e);
                }

                // Wait before retrying
                sleep($retryDelay * $attempt); // Exponential backoff
            }
        }

        // This should never be reached, but PHPStan requires it
        throw new VideoProcessingException('Unexpected end of upload attempts');
    }

    /**
     * Create a temporary local file path for processing
     */
    private function createTemporaryFilePath(string $filename): string
    {
        $tempPath = 'temp/audio_extraction/'.$filename;
        $fullTempPath = Storage::disk($this->tempDisk)->path($tempPath);

        // Ensure temp directory exists (temp disk should always be local)
        $this->ensureDirectoryExists(dirname($fullTempPath));

        return $fullTempPath;
    }

    /**
     * Safely clean up temporary files with error handling
     */
    private function cleanupTemporaryFile(string $filePath): void
    {
        if (empty($filePath)) {
            return;
        }

        try {
            if (file_exists($filePath)) {
                unlink($filePath);
                if (config('media-processing.log_s3_operations', true)) {
                    Log::debug('Temporary file cleaned up', ['file_path' => $filePath]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to clean up temporary file', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - cleanup failures shouldn't break the main process
        }
    }

    /**
     * Get the appropriate output path - temporary for S3 disks, direct for local disks
     */
    private function getProcessingOutputPath(string $filename): array
    {
        $permanentPath = $this->audioPath.'/'.$filename;

        if ($this->isS3Disk($this->permanentDisk)) {
            // For S3 disks, create temporary local file first
            $tempPath = $this->createTemporaryFilePath($filename);

            return [
                'processing_path' => $tempPath,
                'permanent_path' => $permanentPath,
                'use_temp_processing' => true,
            ];
        } else {
            // For local disks, process directly to permanent location
            $fullPermanentPath = Storage::disk($this->permanentDisk)->path($permanentPath);
            $this->ensureDirectoryExists(dirname($fullPermanentPath));

            return [
                'processing_path' => $fullPermanentPath,
                'permanent_path' => $permanentPath,
                'use_temp_processing' => false,
            ];
        }
    }

    /**
     * Check if file exists on specified disk (S3-aware)
     */
    private function fileExists(string $filePath, string $disk): bool
    {
        if ($this->isS3Disk($disk)) {
            return Storage::disk($disk)->exists($filePath);
        }

        return file_exists($filePath);
    }

    /**
     * Get file size on specified disk (S3-aware)
     */
    private function getFileSize(string $filePath, string $disk): int
    {
        if ($this->isS3Disk($disk)) {
            try {
                return Storage::disk($disk)->size($filePath);
            } catch (\Exception $e) {
                return 0;
            }
        }

        return file_exists($filePath) ? filesize($filePath) : 0;
    }

    /**
     * Get local file size (for files that are expected to be local)
     */
    private function getLocalFileSize(string $filePath): int
    {
        return file_exists($filePath) ? filesize($filePath) : 0;
    }

    /**
     * Alias for extractOptimizedAudio for backward compatibility
     *
     * @deprecated Use extractOptimizedAudio instead
     */
    public function extractOptimizedAudioFromSegment(
        string $inputVideoPath,
        object $segment,
        ?string $outputFilename = null
    ): array {
        return $this->extractOptimizedAudio($inputVideoPath, $segment, $outputFilename);
    }
}
