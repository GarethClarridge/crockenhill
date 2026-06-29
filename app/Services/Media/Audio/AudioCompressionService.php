<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Exceptions\VideoProcessingException;
use App\Services\Processing\StorageAdapterHelper;
use App\Traits\DetectsStorageType;
use App\Traits\RequiresFfmpeg;
use App\Traits\SanitizesLogData;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Media\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AudioCompressionService
 *
 * Responsible for extracting audio from video with compression, quality
 * validation, and fallback compression logic. Handles S3-aware processing
 * by working locally then delegating uploads through an injected handler.
 */
class AudioCompressionService
{
    use DetectsStorageType;
    use RequiresFfmpeg;
    use SanitizesLogData;

    private string $tempDisk;

    private string $permanentDisk;

    private string $audioPath;

    public function __construct(
        private readonly StorageAdapterHelper $storageHelper
    ) {
        $this->ffmpeg = $storageHelper->createFFMpeg();

        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
        $this->permanentDisk = config('media-processing.storage.sermon_disk', 'public');
        $this->audioPath = config('media-processing.storage.paths.audio', 'sermons/audio');
    }

    /**
     * Extract optimized audio from a video segment with compression validation.
     *
     * Handles S3-aware processing: extracts locally, validates size, applies
     * fallback compression if needed, then uploads to permanent storage.
     *
     * @return array{audio_path: string, full_path: string, original_size: int, final_size: int, compression_applied: bool, compression_ratio: float, valid_for_transcription: bool}
     *
     * @throws \Exception
     * @throws VideoProcessingException
     * @throws \Throwable
     */
    public function extractOptimizedAudio(
        string $inputVideoPath,
        object $segment,
        ?string $outputFilename = null,
        ?string $permanentDisk = null,
        ?string $audioPath = null,
        ?callable $uploadHandler = null
    ): array {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;
        $duration = $endTime - $startTime;

        $outputFilename = $outputFilename ?: Str::uuid().'_sermon_optimized.mp3';

        $resolvedPermanentDisk = $permanentDisk ?? $this->permanentDisk;
        $resolvedAudioPath = $audioPath ?? $this->audioPath;

        $pathInfo = $this->getProcessingOutputPath($outputFilename, $resolvedPermanentDisk, $resolvedAudioPath);
        $processingPath = $pathInfo['processing_path'];
        $permanentPath = $pathInfo['permanent_path'];
        $useS3Processing = $pathInfo['use_temp_processing'];

        try {

            Log::info('Starting FFmpeg audio extraction', $this->sanitizeArrayForLog([
                'input_path' => $inputVideoPath,
                'processing_path' => $processingPath,
                'permanent_path' => $permanentPath,
                'use_s3_processing' => $useS3Processing,
                'permanent_disk' => $resolvedPermanentDisk,
                'start_time' => $startTime,
                'duration' => $duration,
            ]));

            if (! file_exists($inputVideoPath)) {
                throw new VideoProcessingException("Input video file not found: {$inputVideoPath}");
            }

            $config = config('media-processing.audio_extraction.transcription_optimized');
            $fallbackConfig = config('media-processing.audio_extraction.fallback_compression');

            /** @var Video $video */
            $video = $this->requireFfmpeg()->open($inputVideoPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($config['bitrate']);
            $format->setAudioChannels($config['channels']);

            $video->clip(TimeCode::fromSeconds($startTime), TimeCode::fromSeconds($duration))->save($format, $processingPath);

            // CRITICAL: Immediately check if FFmpeg actually created the file
            if (! file_exists($processingPath)) {
                throw new VideoProcessingException("FFmpeg failed to create audio file at: {$processingPath}. Check FFmpeg installation and permissions.");
            }

            $validation = $this->validateAudioFileSize($processingPath);

            Log::info('FFmpeg audio extraction verification', $this->sanitizeArrayForLog([
                'processing_path' => $processingPath,
                'permanent_path' => $permanentPath,
                'file_exists' => true,
                'file_size' => filesize($processingPath),
                'validation_passed' => $validation['valid'],
                'start_time' => $startTime,
                'duration' => $duration,
            ]));

            if (! $validation['valid']) {
                Log::info('Audio file too large, applying fallback compression', $this->sanitizeArrayForLog([
                    'original_size' => $validation['file_size'],
                    'max_size' => $validation['max_size'],
                    'processing_path' => $processingPath,
                ]));

                $compressionResult = $this->compressAudioForTranscription($processingPath, $fallbackConfig);
                $fallbackPath = $compressionResult['compressed_path'];
                $finalValidation = $this->validateAudioFileSize($fallbackPath);

                if (! $finalValidation['valid']) {
                    $this->cleanupTemporaryFile($fallbackPath);

                    throw new VideoProcessingException(
                        "Fallback compression failed to produce a transcribable audio file (size: {$finalValidation['file_size']} bytes, max: {$finalValidation['max_size']} bytes)."
                    );
                }

                $compressionRatio = round($validation['file_size'] / $finalValidation['file_size'], 2);

                Log::info('Fallback compression completed', $this->sanitizeArrayForLog([
                    'compressed_path' => $compressionResult['relative_path'],
                    'original_size' => $validation['file_size'],
                    'final_size' => $finalValidation['file_size'],
                    'compression_ratio' => $compressionRatio,
                    'valid_for_transcription' => $finalValidation['valid'],
                ]));

                if ($useS3Processing) {
                    $this->uploadToPermanentStorage($fallbackPath, $permanentPath, $uploadHandler);
                    $this->cleanupTemporaryFile($processingPath);
                    $this->cleanupTemporaryFile($fallbackPath);
                    Log::info('S3 upload completed and temp files cleaned up', $this->sanitizeArrayForLog([
                        'permanent_path' => $permanentPath,
                        'compressed_used' => true,
                    ]));
                }

                return [
                    'audio_path' => $permanentPath,
                    'full_path' => $useS3Processing ? Storage::disk($resolvedPermanentDisk)->url($permanentPath) : $fallbackPath,
                    'original_size' => $validation['file_size'],
                    'final_size' => $finalValidation['file_size'],
                    'compression_applied' => true,
                    'compression_ratio' => $compressionRatio,
                    'valid_for_transcription' => $finalValidation['valid'],
                ];
            }

            if ($useS3Processing) {
                $this->uploadToPermanentStorage($processingPath, $permanentPath, $uploadHandler);
                $this->cleanupTemporaryFile($processingPath);
                Log::info('S3 upload completed and temp file cleaned up', $this->sanitizeArrayForLog([
                    'permanent_path' => $permanentPath,
                    'compressed_used' => false,
                ]));
            }

            Log::info('Optimized audio extracted from segment without compression', $this->sanitizeArrayForLog([
                'input_path' => $inputVideoPath,
                'final_path' => $permanentPath,
                'file_size' => $validation['file_size'],
                'file_size_mb' => round($validation['file_size'] / 1024 / 1024, 1),
                'start_time' => $startTime,
                'duration' => $duration,
                'valid_for_transcription' => $validation['valid'],
                'uploaded_to_s3' => $useS3Processing,
            ]));

            return [
                'audio_path' => $permanentPath,
                'full_path' => $useS3Processing ? Storage::disk($resolvedPermanentDisk)->url($permanentPath) : $processingPath,
                'original_size' => $validation['file_size'],
                'final_size' => $validation['file_size'],
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => $validation['valid'],
            ];

        } catch (\Exception $e) {
            Log::error('Failed to extract optimized audio from segment', $this->sanitizeArrayForLog([
                'error' => $e->getMessage(),
                'input_path' => $inputVideoPath,
                'processing_path' => $processingPath,
                'permanent_path' => $permanentPath,
                'segment_start' => $startTime,
                'segment_duration' => $duration,
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            throw $e;
        }
    }

    private function uploadToPermanentStorage(string $localFilePath, string $permanentPath, ?callable $uploadHandler): void
    {
        if ($uploadHandler === null) {
            throw new VideoProcessingException('S3 upload handler is required for S3 processing');
        }

        $uploadHandler($localFilePath, $permanentPath);
    }

    /**
     * Validate audio file size for transcription services.
     *
     * @return array{valid: bool, file_size: int, max_size: int, size_mb: float, max_size_mb: float}
     */
    private function validateAudioFileSize(string $audioPath): array
    {
        $fileSize = file_exists($audioPath) ? filesize($audioPath) : 0;
        if ($fileSize === false) {
            $fileSize = 0;
        }

        $maxSize = (int) config('media-processing.audio_extraction.transcription_optimized.max_file_size', 0);

        return [
            'valid' => $fileSize <= $maxSize && $fileSize > 0,
            'file_size' => $fileSize,
            'max_size' => $maxSize,
            'size_mb' => round($fileSize / 1024 / 1024, 1),
            'max_size_mb' => round($maxSize / 1024 / 1024, 1),
        ];
    }

    /**
     * Apply fallback compression to an audio file that exceeds the size limit.
     * Deletes the original file after successful compression.
     *
     * @param  array{bitrate: int, channels: int}  $compressionSettings
     * @return array{compressed_path: string, relative_path: string}
     */
    private function compressAudioForTranscription(string $inputPath, array $compressionSettings): array
    {
        $compressedPath = str_replace('.mp3', '_compressed.mp3', $inputPath);

        try {
            $audio = $this->requireFfmpeg()->open($inputPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($compressionSettings['bitrate']);
            $format->setAudioChannels($compressionSettings['channels']);

            $audio->save($format, $compressedPath);

            unlink($inputPath);

            $diskPath = Storage::disk($this->permanentDisk)->path('');
            $relativePath = str_starts_with($compressedPath, $diskPath)
                ? ltrim(str_replace($diskPath, '', $compressedPath), '/')
                : $compressedPath;

            Log::info('Applied fallback compression to audio file', $this->sanitizeArrayForLog([
                'original_path' => $inputPath,
                'compressed_path' => $compressedPath,
                'relative_path' => $relativePath,
                'bitrate' => $compressionSettings['bitrate'],
                'channels' => $compressionSettings['channels'],
            ]));

            return [
                'compressed_path' => $compressedPath,
                'relative_path' => $relativePath,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to apply fallback compression', $this->sanitizeArrayForLog([
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            throw $e;
        }
    }

    /**
     * Get the appropriate output path — temporary for S3 disks, direct for local disks.
     *
     * @return array{processing_path: string, permanent_path: string, use_temp_processing: bool}
     */
    private function getProcessingOutputPath(string $filename, string $permanentDisk, string $audioPath): array
    {
        return $this->storageHelper->getProcessingOutputPath(
            $filename,
            $audioPath,
            $permanentDisk,
            $this->tempDisk,
            'temp/audio_extraction'
        );
    }

    private function cleanupTemporaryFile(string $filePath): void
    {
        $this->storageHelper->cleanupTempFile($filePath);
    }
}
