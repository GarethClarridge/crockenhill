<?php

namespace App\Services;

use App\Data\LivestreamSegment;
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
        $this->ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
            'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            'timeout' => config('livestream-processing.processing_timeout'),
        ]);

        $this->tempDisk = config('livestream-processing.temp_disk', 'local');
        $this->permanentDisk = config('livestream-processing.sermon_disk', 'local');
        $this->audioPath = config('livestream-processing.storage.audio_path', 'sermons/audio');
    }

    /**
     * Extract video segment with stream copy (no re-encoding) - primary method
     *
     * @param string $inputPath Path to the original video file
     * @param object $segment Segment data with start_time and end_time
     * @param array $options Extraction options
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
                '-ss', (string)$startTime,
                '-t', (string)$duration,
                '-c', 'copy',  // Stream copy - no re-encoding
                '-avoid_negative_ts', 'make_zero',  // Handle timestamp issues
                escapeshellarg($tempPath)
            ];

            $commandString = implode(' ', $command);
            Log::info('Executing FFmpeg stream copy command', [
                'command' => $commandString,
                'start_time' => $startTime,
                'duration' => $duration,
            ]);

            exec($commandString . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                Log::warning('Stream copy failed, attempting fallback to re-encoding', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output)
                ]);
                
                return $this->extractSegmentWithReencoding($inputPath, $segment, $outputFilename);
            }

            if (!file_exists($tempPath)) {
                throw new \Exception('Output file was not created: ' . $tempPath);
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
        if (!is_dir($directory)) {
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
            $format = new X264();
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

            $video = $this->ffmpeg->open($inputVideoPath);

            $format = new Mp3;
            
            // Apply compression options if provided
            if (!empty($compressionOptions)) {
                $format->setAudioKiloBitrate($compressionOptions['bitrate'] ?? 128);
                if (isset($compressionOptions['channels'])) {
                    $format->setAudioChannels($compressionOptions['channels']);
                }
            } else {
                $format->setAudioKiloBitrate(128);
            }

            $startTime = TimeCode::fromSeconds($startTime);
            $durationTime = TimeCode::fromSeconds($duration);

            $video->clip($startTime, $durationTime)
                ->save($format, $fullOutputPath);

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

            $this->ensureDirectoryExists(dirname($fullOutputPath));

            $config = config('livestream-processing.audio_extraction.transcription_optimized');
            $fallbackConfig = config('livestream-processing.audio_extraction.fallback_compression');

            $video = $this->ffmpeg->open($inputVideoPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($config['bitrate']);
            $format->setAudioChannels($config['channels']);

            $startTimeCode = TimeCode::fromSeconds($startTime);
            $durationTimeCode = TimeCode::fromSeconds($duration);

            $video->clip($startTimeCode, $durationTimeCode)
                ->save($format, $fullOutputPath);

            $validation = $this->validateAudioFileSize($fullOutputPath);

            if (!$validation['valid']) {
                Log::info('Audio file too large, applying fallback compression', [
                    'original_size' => $validation['file_size'],
                    'max_size' => $validation['max_size'],
                ]);

                $fallbackPath = $this->compressAudioForTranscription($fullOutputPath, $fallbackConfig);
                $finalValidation = $this->validateAudioFileSize($fallbackPath);

                return [
                    'audio_path' => $outputPath,
                    'full_path' => $fallbackPath,
                    'original_size' => $validation['file_size'],
                    'final_size' => $finalValidation['file_size'],
                    'compression_applied' => true,
                    'compression_ratio' => $validation['file_size'] / $finalValidation['file_size'],
                    'valid_for_transcription' => $finalValidation['valid'],
                ];
            }

            Log::info('Optimized audio extracted from segment', [
                'input_path' => $inputVideoPath,
                'output_path' => $outputPath,
                'file_size' => $validation['file_size'],
                'start_time' => $startTime,
                'duration' => $duration,
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
            Log::error('Failed to extract optimized audio from segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputVideoPath,
                'segment_start' => $startTime,
                'segment_duration' => $duration,
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
    private function compressAudioForTranscription(string $inputPath, array $compressionSettings): string
    {
        $compressedPath = str_replace('.mp3', '_compressed.mp3', $inputPath);

        try {
            $audio = $this->ffmpeg->open($inputPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($compressionSettings['bitrate']);
            $format->setAudioChannels($compressionSettings['channels']);

            $audio->save($format, $compressedPath);

            unlink($inputPath);

            Log::info('Applied fallback compression to audio file', [
                'original_path' => $inputPath,
                'compressed_path' => $compressedPath,
                'bitrate' => $compressionSettings['bitrate'],
            ]);

            return $compressedPath;

        } catch (\Exception $e) {
            Log::error('Failed to apply fallback compression', [
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
            ]);

            throw $e;
        }
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}