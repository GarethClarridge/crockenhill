<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AudioExtractionServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AudioExtractionService - Handles audio extraction and processing
 *
 * Extracted from VideoProcessingService to follow Single Responsibility Principle.
 * Handles all audio-related operations including extraction from video and compression.
 */
class AudioExtractionService implements AudioExtractionServiceInterface
{
    /**
     * Extract audio from video file optimized for transcription
     */
    public function extractFromVideo(string $videoPath, float $duration): string
    {
        $audioConfig = config('livestream-processing.audio_extraction.transcription_optimized');

        $outputPath = storage_path('app/temp/'.Str::uuid().'.mp3');
        $this->ensureDirectoryExists(dirname($outputPath));

        try {
            $ffmpeg = \FFMpeg\FFMpeg::create([
                'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
                'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            ]);

            $video = $ffmpeg->open($videoPath);

            $format = new \FFMpeg\Format\Audio\Mp3;
            $format->setAudioKiloBitrate($audioConfig['bitrate'] ?? 48);
            $format->setAudioChannels($audioConfig['channels'] ?? 1);

            $video->save($format, $outputPath);

            // Check file size and compress if needed
            $fileSize = $this->getLocalFileSize($outputPath);
            $maxSize = $audioConfig['max_file_size'] ?? (25 * 1024 * 1024);

            if ($fileSize > $maxSize) {
                Log::info('Audio file too large, applying fallback compression', [
                    'original_size' => $fileSize,
                    'max_size' => $maxSize,
                ]);

                $outputPath = $this->compressForTranscription($outputPath);
            }

            Log::info('Audio extracted from video', [
                'video_path' => $videoPath,
                'audio_path' => $outputPath,
                'duration' => $duration,
                'file_size' => $this->getLocalFileSize($outputPath),
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('Failed to extract audio from video', [
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Compress audio file for transcription service requirements
     */
    public function compressForTranscription(string $inputPath): string
    {
        $fallbackConfig = config('livestream-processing.audio_extraction.fallback_compression');
        $compressedPath = storage_path('app/temp/'.Str::uuid().'_compressed.mp3');

        try {
            $ffmpeg = \FFMpeg\FFMpeg::create([
                'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
                'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            ]);

            $audio = $ffmpeg->open($inputPath);

            $format = new \FFMpeg\Format\Audio\Mp3;
            $format->setAudioKiloBitrate($fallbackConfig['bitrate'] ?? 32);
            $format->setAudioChannels($fallbackConfig['channels'] ?? 1);

            $audio->save($format, $compressedPath);

            // Remove original file
            if (file_exists($inputPath)) {
                unlink($inputPath);
            }

            return $compressedPath;

        } catch (\Exception $e) {
            Log::error('Failed to compress audio file', [
                'input_path' => $inputPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate audio file meets processing requirements
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        $allowedMimeTypes = ['audio/mpeg', 'audio/wav', 'audio/mp4', 'audio/x-m4a', 'audio/m4a'];
        $allowedExtensions = ['mp3', 'wav', 'm4a', 'mp4'];
        $maxFileSize = config('sermon-processing.processing.max_file_size', 104857600); // 100MB

        if (! $file->isValid()) {
            throw new \InvalidArgumentException('Invalid audio file uploaded');
        }

        if ($file->getSize() > $maxFileSize) {
            $maxSizeMB = round($maxFileSize / (1024 * 1024));
            throw new \InvalidArgumentException("Audio file size exceeds maximum limit of {$maxSizeMB}MB");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $allowedExtensions)) {
            $allowed = implode(', ', $allowedExtensions);
            throw new \InvalidArgumentException("Audio file extension '{$extension}' not allowed. Allowed: {$allowed}");
        }

        $mimeType = $file->getMimeType();
        if (! in_array($mimeType, $allowedMimeTypes)) {
            throw new \InvalidArgumentException("Audio file MIME type '{$mimeType}' not allowed");
        }
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
        return file_exists($filePath) ? filesize($filePath) : 0;
    }
}
