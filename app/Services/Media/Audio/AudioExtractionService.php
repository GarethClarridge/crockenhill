<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Enums\MediaType;
use App\Exceptions\InvalidFileException;
use App\Services\Processing\MediaValidationService;
use App\Traits\SanitizesLogData;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AudioExtractionService - Handles audio extraction and processing
 *
 * Extracted from VideoProcessingService to follow Single Responsibility Principle.
 * Handles all audio-related operations including extraction from video and compression.
 */
class AudioExtractionService
{
    use SanitizesLogData;

    public function __construct(private readonly MediaValidationService $mediaValidation) {}

    /**
     * Extract audio from a video file, optimized for transcription.
     *
     * Produces a low-bitrate mono MP3 file suitable for AI transcription services.
     * Automatically applies fallback compression if the extracted file exceeds
     * the maximum size limit (typically 25MB).
     *
     * @param  string  $videoPath  Absolute path to the source video file
     * @param  float  $duration  Total duration of the video in seconds
     * @return string Absolute path to the extracted audio file
     *
     * @throws \Exception If FFmpeg fails or the output directory is not writable
     */
    public function extractFromVideo(string $videoPath, float $duration): string
    {
        $audioConfig = config('media-processing.audio_extraction.transcription_optimized');

        $outputPath = storage_path('app/temp/'.Str::uuid().'.mp3');
        $this->ensureDirectoryExists(dirname($outputPath));

        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);

            $video = $ffmpeg->open($videoPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($audioConfig['bitrate'] ?? 48);
            $format->setAudioChannels($audioConfig['channels'] ?? 1);

            $video->save($format, $outputPath);

            // Check file size and compress if needed
            $fileSize = $this->getLocalFileSize($outputPath);
            $maxSize = $audioConfig['max_file_size'] ?? (25 * 1024 * 1024);

            if ($fileSize > $maxSize) {
                Log::info('Audio file too large, applying fallback compression', $this->sanitizeArrayForLog([
                    'original_size' => $fileSize,
                    'max_size' => $maxSize,
                ]));

                $outputPath = $this->compressForTranscription($outputPath);
            }

            Log::info('Audio extracted from video', $this->sanitizeArrayForLog([
                'video_path' => $videoPath,
                'audio_path' => $outputPath,
                'duration' => $duration,
                'file_size' => $this->getLocalFileSize($outputPath),
            ]));

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('Failed to extract audio from video', $this->sanitizeArrayForLog([
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            throw $e;
        }
    }

    /**
     * Re-encode an audio file with lower quality settings to reduce file size.
     *
     * Used as a fallback when the initial extraction produces a file too large
     * for transcription API limits. The original file is deleted upon success.
     *
     * @param  string  $inputPath  Absolute path to the source audio file
     * @return string Absolute path to the compressed mono MP3 file
     *
     * @throws \Exception If FFmpeg compression fails
     */
    public function compressForTranscription(string $inputPath): string
    {
        $fallbackConfig = config('media-processing.audio_extraction.fallback_compression');
        $compressedPath = storage_path('app/temp/'.Str::uuid().'_compressed.mp3');

        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);

            $audio = $ffmpeg->open($inputPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($fallbackConfig['bitrate'] ?? 32);
            $format->setAudioChannels($fallbackConfig['channels'] ?? 1);

            $audio->save($format, $compressedPath);

            // Remove original file
            if (file_exists($inputPath)) {
                unlink($inputPath);
            }

            return $compressedPath;

        } catch (\Exception $e) {
            Log::error('Failed to compress audio file', $this->sanitizeArrayForLog([
                'input_path' => $inputPath,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            throw $e;
        }
    }

    /**
     * Validate an uploaded audio file against configured size and type limits.
     *
     * @param  UploadedFile  $file  The uploaded file to validate
     *
     * @throws InvalidFileException If the file is too large or an unsupported format
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        $this->mediaValidation->validateUploadedFile(MediaType::Audio, $file);
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Get local file size (for files that are expected to be local)
     */
    private function getLocalFileSize(string $filePath): int
    {
        if (! file_exists($filePath)) {
            return 0;
        }

        $fileSize = filesize($filePath);

        return $fileSize === false ? 0 : $fileSize;
    }
}
