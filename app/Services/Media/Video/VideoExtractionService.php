<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Exceptions\VideoProcessingException;
use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Traits\RequiresFfmpeg;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Media\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoExtractionService
{
    use RequiresFfmpeg;

    private string $tempDisk;

    private string $permanentDisk;

    private string $audioPath;

    public function __construct(
        private readonly AudioCompressionService $audioCompressor,
        private readonly StorageAdapterHelper $storageHelper
    ) {
        $this->ffmpeg = $storageHelper->createFFMpeg();

        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
        $this->permanentDisk = config('media-processing.storage.sermon_disk', 'public');
        $this->audioPath = config('media-processing.storage.paths.audio', 'sermons/audio');
    }

    /**
     * Extract video segment with stream copy (no re-encoding) - primary method.
     *
     * @param  string  $inputPath  Path to the original video file
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  array<string, mixed>  $options  Extraction options
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
     * Extract video segment and return as file path (for storage operations).
     *
     * @param  string  $inputPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return string The relative path to the extracted video file
     *
     * @throws VideoProcessingException If output file was not created
     * @throws \Exception For underlying system or FFmpeg errors
     */
    public function extractSegmentAsFile(string $inputPath, object $segment, ?string $outputFilename = null): string
    {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        try {
            // Use Laravel storage disk for consistency with other file operations
            $tempDisk = config('media-processing.storage.temp_disk', 'local');
            $relativePath = 'temp/'.Str::uuid().'.mp4';
            $tempPath = Storage::disk($tempDisk)->path($relativePath);

            // Ensure temp directory exists using storage disk
            Storage::disk($tempDisk)->makeDirectory(dirname($relativePath));

            $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path');
            $duration = $endTime - $startTime;

            /**
             * A stream copy inherits the source bitrate, which is right for the
             * current recording setup but wasteful for camera-original material.
             * Deciding here rather than in the caller keeps the weekly upload and
             * the historic import on one rule.
             */
            if ($this->shouldReencodeSource($inputPath)) {
                return $this->extractSegmentWithReencoding($inputPath, $segment, $outputFilename);
            }

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

            // Check if file was created - Storage::exists expects a disk-relative path
            if (! $this->fileExists($relativePath, $tempDisk)) {
                throw new VideoProcessingException('Output file was not created: '.$tempPath);
            }

            Log::info('Video segment extracted with stream copy (original quality)', [
                'input_path' => $inputPath,
                'output_path' => $tempPath,
                'relative_path' => $relativePath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'output_size' => $this->getFileSize($relativePath, $tempDisk),
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
     * @param  string  $inputPath  Absolute path to the source video
     * @param  array<int, array{start_time: float, end_time: float}>  $segments  List of spans to join
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return string The relative path to the concatenated video file
     *
     * @throws VideoProcessingException If concatenation fails or no valid segments provided
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

            if (! $this->fileExists($outputRelativePath, $tempDisk)) {
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
     * Extract video segment and return as UploadedFile (for processing pipelines).
     *
     * @param  string  $inputPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return UploadedFile The extracted segment as a Laravel UploadedFile
     *
     * @throws VideoProcessingException If extraction fails or times are invalid
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

        Log::info('Using re-encoding for video extraction', [
            'input_path' => $inputPath,
            'start_time' => $startTime,
            'duration' => $duration,
        ]);

        /**
         * This writes through the configured temp disk and returns a disk-relative
         * path, matching {@see extractSegmentAsFile()}. It previously wrote to
         * storage_path('app/temp') and returned an absolute path, which ignored
         * `media-processing.storage.temp_disk` and handed callers a path shape
         * they store verbatim in `processing_log.video_file_path`.
         */
        $tempDisk = config('media-processing.storage.temp_disk', 'local');
        $relativePath = 'temp/'.Str::uuid().'.mp4';
        $tempPath = Storage::disk($tempDisk)->path($relativePath);

        Storage::disk($tempDisk)->makeDirectory(dirname($relativePath));

        try {
            $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path');

            $command = [
                $ffmpegPath,
                '-i', escapeshellarg($inputPath),
                '-ss', (string) $startTime,
                '-t', (string) $duration,
                '-c:v', 'libx264',
                '-crf', (string) (int) config('media-processing.video_extraction.reencode_crf', 23),
                '-preset', (string) config('media-processing.video_extraction.reencode_preset', 'medium'),
                '-c:a', 'aac',
                '-avoid_negative_ts', 'make_zero',
                escapeshellarg($tempPath),
            ];

            exec(implode(' ', $command).' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                throw new VideoProcessingException(
                    'FFmpeg re-encode failed: '.implode("\n", $output)
                );
            }

            if (! $this->fileExists($relativePath, $tempDisk)) {
                throw new VideoProcessingException('Re-encoded output file was not created: '.$tempPath);
            }

            Log::info('Video segment extracted with re-encoding', [
                'input_path' => $inputPath,
                'output_path' => $tempPath,
                'relative_path' => $relativePath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'output_size' => $this->getFileSize($relativePath, $tempDisk),
            ]);

            return $relativePath;

        } catch (\Exception $e) {
            Log::error('Re-encoding extraction failed', [
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            throw $e;
        }
    }

    /**
     * Whether this source is wasteful enough to be worth re-encoding its extracts.
     *
     * Fails safe: an unreadable bitrate, an unset threshold or a probe error all
     * leave the existing stream-copy behaviour in place, because a stream copy is
     * never wrong — only sometimes larger than it needs to be.
     */
    private function shouldReencodeSource(string $inputPath): bool
    {
        $thresholdMbps = (float) config('media-processing.video_extraction.reencode_above_mbps', 0.0);

        if ($thresholdMbps <= 0.0) {
            return false;
        }

        $bitrateMbps = $this->probeSourceBitrateMbps($inputPath);

        if ($bitrateMbps === null || $bitrateMbps <= $thresholdMbps) {
            return false;
        }

        Log::info('Re-encoding video extract: source bitrate exceeds threshold', [
            'input_path' => $inputPath,
            'source_bitrate_mbps' => round($bitrateMbps, 2),
            'threshold_mbps' => $thresholdMbps,
        ]);

        return true;
    }

    /**
     * Overall container bitrate of the source, in Mbps, or null when unreadable.
     */
    private function probeSourceBitrateMbps(string $inputPath): ?float
    {
        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path');

        if (! is_string($ffprobePath) || $ffprobePath === '') {
            return null;
        }

        $command = implode(' ', [
            $ffprobePath,
            '-v', 'error',
            '-show_entries', 'format=bit_rate',
            '-of', 'default=nw=1:nk=1',
            escapeshellarg($inputPath),
        ]);

        exec($command.' 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0) {
            return null;
        }

        $raw = trim(implode('', $output));

        if (! is_numeric($raw) || (float) $raw <= 0.0) {
            return null;
        }

        return (float) $raw / 1_000_000;
    }

    /**
     * Extract audio from a video segment.
     *
     * @param  string  $inputVideoPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  array<string, mixed>  $compressionOptions  Technical options (bitrate, channels)
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return string The storage path to the extracted audio file
     *
     * @throws \Exception If extraction or S3 upload fails
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

            /** @var Video $video */
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
     * @param  string  $inputVideoPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @param  string|null  $permanentDisk  Optional disk name override
     * @param  string|null  $audioPath  Optional destination directory override
     * @return array{
     *     audio_path: string,
     *     full_path: string,
     *     original_size: int,
     *     final_size: int,
     *     compression_applied: bool,
     *     compression_ratio: float,
     *     valid_for_transcription: bool
     * }
     *
     * @throws \Exception If extraction fails
     */
    public function extractOptimizedAudio(
        string $inputVideoPath,
        object $segment,
        ?string $outputFilename = null,
        ?string $permanentDisk = null,
        ?string $audioPath = null,
    ): array {
        $resolvedPermanentDisk = $permanentDisk ?? $this->permanentDisk;

        return $this->audioCompressor->extractOptimizedAudio(
            $inputVideoPath,
            $segment,
            $outputFilename,
            $resolvedPermanentDisk,
            $audioPath,
            fn (string $localFilePath, string $permanentPath): string => $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $resolvedPermanentDisk)
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

    private function fileExists(string $filePath, string $disk): bool
    {
        return $this->storageHelper->fileExists($filePath, $disk);
    }

    private function getFileSize(string $filePath, string $disk): int
    {
        return $this->storageHelper->fileSize($filePath, $disk);
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
