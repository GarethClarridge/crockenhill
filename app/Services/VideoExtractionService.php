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
        private readonly AudioCompressionService $audioCompressor,
        private readonly StorageAdapterHelper $storageHelper
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
    /**
     * @param  array<string, mixed>  $options
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
     * Extract and hard-join multiple spans using FFmpeg concat demuxer.
     *
     * @param  array<int, array{start_time: float, end_time: float}>  $segments
     */
    public function extractConcatenatedSegmentAsFile(
        string $inputPath,
        array $segments,
        ?string $outputFilename = null
    ): string {
        $normalizedSegments = collect($segments)
            ->filter(fn (array $segment): bool => $segment['end_time'] > $segment['start_time'])
            ->values();

        if ($normalizedSegments->isEmpty()) {
            throw new VideoProcessingException('No valid segments provided for concatenation');
        }

        if ($normalizedSegments->count() === 1) {
            $segment = $normalizedSegments->first();

            return $this->extractSegmentAsFile(
                $inputPath,
                (object) [
                    'start_time' => (float) $segment['start_time'],
                    'end_time' => (float) $segment['end_time'],
                ],
                $outputFilename
            );
        }

        $tempDisk = config('media-processing.storage.temp_disk', 'local');
        $concatFileRelativePath = 'temp/concat/'.Str::uuid().'.txt';
        $concatFileAbsolutePath = Storage::disk($tempDisk)->path($concatFileRelativePath);
        $outputRelativePath = 'temp/'.($outputFilename ?? Str::uuid().'.mp4');
        $outputAbsolutePath = Storage::disk($tempDisk)->path($outputRelativePath);
        $clipRelativePaths = [];

        Storage::disk($tempDisk)->makeDirectory(dirname($concatFileRelativePath));
        Storage::disk($tempDisk)->makeDirectory(dirname($outputRelativePath));

        try {
            foreach ($normalizedSegments as $index => $segment) {
                $clipRelativePaths[] = $this->extractSegmentAsFile(
                    $inputPath,
                    (object) [
                        'start_time' => (float) $segment['start_time'],
                        'end_time' => (float) $segment['end_time'],
                    ],
                    'concat-part-'.$index.'-'.Str::uuid().'.mp4'
                );
            }

            $concatListContent = $this->buildConcatListContent($clipRelativePaths, $tempDisk);
            file_put_contents($concatFileAbsolutePath, $concatListContent);

            $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path');
            $concatCommand = [
                $ffmpegPath,
                '-f', 'concat',
                '-safe', '0',
                '-i', escapeshellarg($concatFileAbsolutePath),
                '-c', 'copy',
                '-y',
                escapeshellarg($outputAbsolutePath),
            ];

            $concatCommandString = implode(' ', $concatCommand);
            exec($concatCommandString.' 2>&1', $concatOutput, $concatReturnCode);

            if ($concatReturnCode !== 0) {
                Log::warning('FFmpeg concat stream copy failed; retrying with re-encode fallback', [
                    'command' => $concatCommandString,
                    'output' => implode("\n", $concatOutput),
                ]);

                $fallbackCommand = [
                    $ffmpegPath,
                    '-f', 'concat',
                    '-safe', '0',
                    '-i', escapeshellarg($concatFileAbsolutePath),
                    '-c:v', 'libx264',
                    '-c:a', 'aac',
                    '-y',
                    escapeshellarg($outputAbsolutePath),
                ];

                $fallbackCommandString = implode(' ', $fallbackCommand);
                exec($fallbackCommandString.' 2>&1', $fallbackOutput, $fallbackReturnCode);

                if ($fallbackReturnCode !== 0) {
                    throw new VideoProcessingException('FFmpeg concat failed: '.implode("\n", $fallbackOutput));
                }
            }

            if (! $this->fileExists($outputAbsolutePath, $tempDisk)) {
                throw new VideoProcessingException('Concatenated output file was not created');
            }

            return $outputRelativePath;
        } finally {
            foreach ($clipRelativePaths as $clipRelativePath) {
                Storage::disk($tempDisk)->delete($clipRelativePath);
            }

            if (file_exists($concatFileAbsolutePath)) {
                unlink($concatFileAbsolutePath);
            }
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
            $video = $this->requireFfmpeg()->open($inputPath);

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
     *
     * @param  array<string, mixed>  $compressionOptions
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
            $video = $this->requireFfmpeg()->open($inputVideoPath);

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
            $segmentStart = null;
            if (property_exists($segment, 'startTime')) {
                $segmentStart = $segment->startTime;
            } elseif (property_exists($segment, 'start_time')) {
                $segmentStart = $segment->start_time;
            }

            Log::error('Failed to extract audio from video segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputVideoPath,
                'segment_start' => $segmentStart,
                'compression_options' => $compressionOptions,
            ]);

            throw $e;
        }
    }

    /**
     * Extract optimized audio from segment with compression validation.
     * Delegates to AudioCompressionService; passes its own S3 upload handler.
     *
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
    public function extractOptimizedAudio(
        string $inputVideoPath,
        object $segment,
        ?string $outputFilename = null
    ): array {
        return $this->audioCompressor->extractOptimizedAudio(
            $inputVideoPath,
            $segment,
            $outputFilename,
            fn (string $localFilePath, string $permanentPath): string => $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $this->permanentDisk)
        );
    }

    /**
     * Upload a local file to permanent storage with exponential-backoff retry.
     */
    private function uploadToPermanentStorage(string $localFilePath, string $permanentPath): string
    {
        return $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $this->permanentDisk);
    }

    private function cleanupTemporaryFile(string $filePath): void
    {
        $this->storageHelper->cleanupTempFile($filePath);
    }

    /**
     * Get the appropriate output path - temporary for S3 disks, direct for local disks.
     *
     * @return array{processing_path: string, permanent_path: string, use_temp_processing: bool}
     */
    private function getProcessingOutputPath(string $filename): array
    {
        return $this->storageHelper->getProcessingOutputPath(
            $filename,
            $this->audioPath,
            $this->permanentDisk,
            $this->tempDisk,
            'temp/audio_extraction'
        );
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

        if (! file_exists($filePath)) {
            return 0;
        }

        $fileSize = filesize($filePath);

        return $fileSize === false ? 0 : $fileSize;
    }

    /**
     * Alias for extractOptimizedAudio for backward compatibility
     *
     * @deprecated Use extractOptimizedAudio instead
     *
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
        object $segment,
        ?string $outputFilename = null
    ): array {
        return $this->extractOptimizedAudio($inputVideoPath, $segment, $outputFilename);
    }

    private function requireFfmpeg(): FFMpeg
    {
        if (! $this->ffmpeg instanceof FFMpeg) {
            throw new VideoProcessingException('FFmpeg is unavailable in the current environment.');
        }

        return $this->ffmpeg;
    }

    /**
     * @param  array<int, string>  $clipRelativePaths
     */
    private function buildConcatListContent(array $clipRelativePaths, string $disk): string
    {
        $lines = [];

        foreach ($clipRelativePaths as $clipRelativePath) {
            $absolutePath = Storage::disk($disk)->path($clipRelativePath);
            $safeAbsolutePath = str_replace("'", "'\\''", $absolutePath);
            $lines[] = "file '{$safeAbsolutePath}'";
        }

        return implode("\n", $lines)."\n";
    }
}
