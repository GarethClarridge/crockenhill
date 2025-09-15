<?php

namespace App\Services;

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
    private FFMpeg $ffmpeg;

    private string $tempDisk;

    private string $permanentDisk;

    private string $audioPath;

    public function __construct()
    {
        $ffmpegPath = config('livestream-processing.ffmpeg_path');
        $ffprobePath = config('livestream-processing.ffprobe_path');

        // Validate FFmpeg binary exists and is executable (skip in testing environment)
        if (! app()->environment('testing')) {
            if (! $ffmpegPath || ! file_exists($ffmpegPath) || ! is_executable($ffmpegPath)) {
                throw new \Exception("FFmpeg binary not found or not executable at: {$ffmpegPath}");
            }

            if (! $ffprobePath || ! file_exists($ffprobePath) || ! is_executable($ffprobePath)) {
                throw new \Exception("FFprobe binary not found or not executable at: {$ffprobePath}");
            }
        }

        try {
            $this->ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => $ffmpegPath,
                'ffprobe.binaries' => $ffprobePath,
                'timeout' => config('livestream-processing.processing_timeout'),
            ]);

            Log::info('FFmpeg initialized successfully', [
                'ffmpeg_path' => $ffmpegPath,
                'ffprobe_path' => $ffprobePath,
                'timeout' => config('livestream-processing.processing_timeout'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to initialize FFmpeg', [
                'ffmpeg_path' => $ffmpegPath,
                'ffprobe_path' => $ffprobePath,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception("Failed to initialize FFmpeg: {$e->getMessage()}");
        }

        $this->tempDisk = config('livestream-processing.temp_disk', 'local');
        $this->permanentDisk = config('livestream-processing.sermon_disk', 'public');
        $this->audioPath = config('livestream-processing.storage.audio_path', 'sermons/audio');
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
            $tempDisk = config('livestream-processing.temp_disk', 'local');
            $relativePath = 'temp/'.Str::uuid().'.mp4';
            $tempPath = \Illuminate\Support\Facades\Storage::disk($tempDisk)->path($relativePath);

            // Ensure temp directory exists using storage disk
            \Illuminate\Support\Facades\Storage::disk($tempDisk)->makeDirectory(dirname($relativePath));

            $ffmpegPath = config('livestream-processing.ffmpeg_path');
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

            if (! file_exists($tempPath)) {
                throw new \Exception('Output file was not created: '.$tempPath);
            }

            Log::info('Video segment extracted with stream copy (original quality)', [
                'input_path' => $inputPath,
                'output_path' => $tempPath,
                'relative_path' => $relativePath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'output_size' => filesize($tempPath),
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
            throw new \Exception('Invalid segment times: start time must be less than end time');
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

            throw new \Exception('Failed to extract video segment: '.$e->getMessage());
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
            $outputPath = $this->audioPath.'/'.$outputFilename;
            $fullOutputPath = Storage::disk($this->permanentDisk)->path($outputPath);

            $this->ensureDirectoryExists(dirname($fullOutputPath));

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

            $startTime = TimeCode::fromSeconds($startTime);
            $durationTime = TimeCode::fromSeconds($duration);

            $video->clip($startTime, $durationTime)->save($format, $fullOutputPath);

            Log::info('Audio extracted from video segment', [
                'input_path' => $inputVideoPath,
                'output_path' => $outputPath,
                'start_time' => $segment->startTime ?? $segment->start_time,
                'duration' => $duration,
                'compression_options' => $compressionOptions,
            ]);

            return $outputPath;

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
     * Extract optimized audio from segment with compression validation
     */
    public function extractOptimizedAudio(
        string $inputVideoPath,
        object $segment,
        ?string $outputFilename = null
    ): array {
        try {
            $startTime = $segment->startTime ?? $segment->start_time ?? 0;
            $endTime = $segment->endTime ?? $segment->end_time ?? 0;
            $duration = $endTime - $startTime;

            $outputFilename = $outputFilename ?: Str::uuid().'_sermon_optimized.mp3';
            $outputPath = $this->audioPath.'/'.$outputFilename;
            $fullOutputPath = Storage::disk($this->permanentDisk)->path($outputPath);

            Log::info('Starting FFmpeg audio extraction', [
                'input_path' => $inputVideoPath,
                'output_path' => $fullOutputPath,
                'start_time' => $startTime,
                'duration' => $duration,
                'segment_start' => $segment->startTime ?? 'not_set',
                'segment_end' => $segment->endTime ?? 'not_set',
            ]);

            // Validate input video exists
            if (! file_exists($inputVideoPath)) {
                throw new \Exception("Input video file not found: {$inputVideoPath}");
            }

            $this->ensureDirectoryExists(dirname($fullOutputPath));

            $config = config('livestream-processing.audio_extraction.transcription_optimized');
            $fallbackConfig = config('livestream-processing.audio_extraction.fallback_compression');

            /** @var \FFMpeg\Media\Video $video */
            $video = $this->ffmpeg->open($inputVideoPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($config['bitrate']);
            $format->setAudioChannels($config['channels']);

            $startTimeCode = TimeCode::fromSeconds($startTime);
            $durationTimeCode = TimeCode::fromSeconds($duration);

            $video->clip($startTimeCode, $durationTimeCode)->save($format, $fullOutputPath);

            // CRITICAL: Immediately check if FFmpeg actually created the file
            if (! file_exists($fullOutputPath)) {
                throw new \Exception("FFmpeg failed to create audio file at: {$fullOutputPath}. Check FFmpeg installation and permissions.");
            }

            $validation = $this->validateAudioFileSize($fullOutputPath);

            Log::info('FFmpeg audio extraction verification', [
                'output_path' => $fullOutputPath,
                'file_exists' => true, // File guaranteed to exist by check on line 410
                'file_size' => filesize($fullOutputPath),
                'validation_passed' => $validation['valid'],
                'start_time' => $startTime,
                'duration' => $duration,
            ]);

            if (! $validation['valid']) {
                Log::info('Audio file too large, applying fallback compression', [
                    'original_size' => $validation['file_size'],
                    'max_size' => $validation['max_size'],
                    'original_path' => $outputPath,
                ]);

                $compressionResult = $this->compressAudioForTranscription($fullOutputPath, $fallbackConfig);
                $fallbackPath = $compressionResult['compressed_path'];
                $compressedRelativePath = $compressionResult['relative_path'];
                $finalValidation = $this->validateAudioFileSize($fallbackPath);

                Log::info('Fallback compression completed', [
                    'original_path' => $outputPath,
                    'compressed_path' => $compressedRelativePath,
                    'original_size' => $validation['file_size'],
                    'final_size' => $finalValidation['file_size'],
                    'compression_ratio' => $validation['file_size'] / $finalValidation['file_size'],
                    'valid_for_transcription' => $finalValidation['valid'],
                ]);

                return [
                    'audio_path' => $compressedRelativePath, // Use compressed file's relative path
                    'full_path' => $fallbackPath,
                    'original_size' => $validation['file_size'],
                    'final_size' => $finalValidation['file_size'],
                    'compression_applied' => true,
                    'compression_ratio' => $validation['file_size'] / $finalValidation['file_size'],
                    'valid_for_transcription' => $finalValidation['valid'],
                ];
            }

            Log::info('Optimized audio extracted from segment without compression', [
                'input_path' => $inputVideoPath,
                'output_path' => $outputPath,
                'full_path' => $fullOutputPath,
                'file_size' => $validation['file_size'],
                'file_size_mb' => round($validation['file_size'] / 1024 / 1024, 1),
                'start_time' => $startTime,
                'duration' => $duration,
                'valid_for_transcription' => $validation['valid'],
            ]);

            return [
                'audio_path' => $outputPath,
                'full_path' => $fullOutputPath,
                'original_size' => $validation['file_size'],
                'final_size' => $validation['file_size'],
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => $validation['valid'],
            ];

        } catch (\Exception $e) {
            // Enhanced error logging with system diagnostics
            $diagnostics = $this->getSystemDiagnostics();

            Log::error('Failed to extract optimized audio from segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputVideoPath,
                'output_path' => $fullOutputPath,
                'segment_start' => $startTime,
                'segment_duration' => $duration,
                'input_file_exists' => file_exists($inputVideoPath),
                'input_file_size' => file_exists($inputVideoPath) ? filesize($inputVideoPath) : 0,
                'output_directory_exists' => is_dir(dirname($fullOutputPath)),
                'output_directory_writable' => is_writable(dirname($fullOutputPath)),
                'system_diagnostics' => $diagnostics,
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate audio file size for transcription services
     */
    private function validateAudioFileSize(string $audioPath): array
    {
        $fileSize = file_exists($audioPath) ? filesize($audioPath) : 0;
        $maxSize = config('livestream-processing.audio_extraction.transcription_optimized.max_file_size');

        return [
            'valid' => $fileSize <= $maxSize && $fileSize > 0,
            'file_size' => $fileSize,
            'max_size' => $maxSize,
            'size_mb' => round($fileSize / 1024 / 1024, 1),
            'max_size_mb' => round($maxSize / 1024 / 1024, 1),
        ];
    }

    /**
     * Apply fallback compression to audio file
     */
    private function compressAudioForTranscription(string $inputPath, array $compressionSettings): array
    {
        $compressedPath = str_replace('.mp3', '_compressed.mp3', $inputPath);

        try {
            $audio = $this->ffmpeg->open($inputPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($compressionSettings['bitrate']);
            $format->setAudioChannels($compressionSettings['channels']);

            $audio->save($format, $compressedPath);

            unlink($inputPath);

            // Calculate relative path for database storage
            $diskPath = Storage::disk($this->permanentDisk)->path('');
            $relativePath = $compressedPath;

            if (str_starts_with($compressedPath, $diskPath)) {
                $relativePath = str_replace($diskPath, '', $compressedPath);
                $relativePath = ltrim($relativePath, '/');
            }

            Log::info('Applied fallback compression to audio file', [
                'original_path' => $inputPath,
                'compressed_path' => $compressedPath,
                'relative_path' => $relativePath,
                'bitrate' => $compressionSettings['bitrate'],
                'channels' => $compressionSettings['channels'],
            ]);

            return [
                'compressed_path' => $compressedPath,
                'relative_path' => $relativePath,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to apply fallback compression', [
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true)) {
                throw new \Exception("Failed to create directory: {$directory}");
            }

            Log::info('Created directory for audio extraction', [
                'directory' => $directory,
                'permissions' => '0755',
            ]);
        }

        if (! is_writable($directory)) {
            throw new \Exception("Directory is not writable: {$directory}");
        }
    }

    /**
     * Get system diagnostics for error reporting
     */
    private function getSystemDiagnostics(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 1).'MB',
            'disk_free_space' => disk_free_space(Storage::disk($this->permanentDisk)->path('')) ?
                round(disk_free_space(Storage::disk($this->permanentDisk)->path('')) / 1024 / 1024 / 1024, 1).'GB' : 'unknown',
            'ffmpeg_path' => config('livestream-processing.ffmpeg_path'),
            'ffprobe_path' => config('livestream-processing.ffprobe_path'),
            'permanent_disk' => $this->permanentDisk,
            'audio_path' => $this->audioPath,
        ];
    }
}
