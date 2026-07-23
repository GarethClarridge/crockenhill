<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Contracts\TranscriptionServiceInterface;
use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\TranscriptionException;
use App\Services\Processing\SermonProcessingLogger;
use App\Traits\DetectsStorageType;
use App\Traits\HandlesTranscriptStorage;
use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;

class AudioTranscriptionService implements TranscriptionServiceInterface
{
    use DetectsStorageType;
    use HandlesTranscriptStorage;
    use SanitizesLogData;

    public function __construct(
        private readonly SermonProcessingLogger $logger,
        private readonly TranscriptStorageService $storageService,
        private readonly AudioChunkingService $chunkingService,
        private readonly TranscriptFormatterService $formatter,
    ) {}

    /**
     * Verify OpenAI API key is configured before making API calls.
     * A missing key is a deterministic misconfiguration — retrying will never succeed.
     *
     * @throws NonRetryableTranscriptionException When the API key is not set
     */
    private function ensureApiKeyConfigured(): void
    {
        if (empty(config('media-processing.transcription.openai_api_key'))) {
            throw new NonRetryableTranscriptionException('OpenAI API key not configured for transcription service');
        }
    }

    /**
     * Ensure directory exists (for local operations only)
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true)) {
                throw new Exception("Failed to create directory: {$directory}");
            }
        }
    }

    /**
     * Transcribe audio file to text using OpenAI Whisper API
     *
     * @param  string  $audioFilePath  Path to the audio file
     * @param  string  $processingId  Processing ID for logging
     * @param  string|null  $disk  Disk to use for file operations (defaults to sermon disk)
     * @return string The transcribed text
     *
     * @throws NonRetryableTranscriptionException When the error is deterministic (bad key, oversized file)
     * @throws TranscriptionException When the error is transient (rate limit, network, etc.)
     * @throws Exception For underlying file or storage failures
     */
    public function transcribe(string $audioFilePath, string $processingId = 'unknown', ?string $disk = null): string
    {
        // Verify API key is configured before proceeding
        $this->ensureApiKeyConfigured();

        // Use provided disk or default to sermon disk
        $diskName = $disk ?? config('media-processing.storage.sermon_disk', 'public');
        $isAbsolutePath = $this->isAbsolutePath($audioFilePath);

        $startTime = microtime(true);

        $this->logger->logProcessingStep(
            $processingId,
            'audio_transcription',
            'started',
            ['file_path' => $audioFilePath, 'disk' => $isAbsolutePath ? 'absolute_path' : $diskName]
        );

        if ($isAbsolutePath) {
            if (! file_exists($audioFilePath)) {
                throw new Exception("Audio file not found: {$audioFilePath} on disk: absolute_path");
            }

            $fullPath = $audioFilePath;
            $isS3Disk = false;
        } else {
            // Validate file exists - check both the specified disk and 'public' disk for test compatibility
            $fileExists = Storage::disk($diskName)->exists($audioFilePath);

            // For backward compatibility with tests, also check 'public' disk if primary disk fails
            if (! $fileExists && $diskName !== 'public') {
                $fileExists = Storage::disk('public')->exists($audioFilePath);
                if ($fileExists) {
                    $diskName = 'public'; // Use public disk if file found there
                }
            }

            if (! $fileExists) {
                throw new Exception("Audio file not found: {$audioFilePath} on disk: {$diskName}");
            }

            // Check if this is an S3 disk and handle accordingly
            $isS3Disk = $this->isS3Disk($diskName);

            if ($isS3Disk) {
                // For S3 disks, download file to local temp for processing
                $tempPath = storage_path('app/temp/'.basename($audioFilePath).'_'.time().'.mp3');
                $this->ensureDirectoryExists(dirname($tempPath));

                $audioStream = Storage::disk($diskName)->readStream($audioFilePath);
                file_put_contents($tempPath, $audioStream);
                $fullPath = $tempPath;
            } else {
                // For local disks, use direct path
                $fullPath = Storage::disk($diskName)->path($audioFilePath);
            }
        }

        // Validate file size and compress if needed
        $processedFilePath = $this->validateAndCompressIfNeeded($fullPath, $processingId);

        $fileSize = filesize($processedFilePath);
        if ($fileSize === false) {
            $fileSize = null;
        }

        $this->logger->logFileOperation(
            $processingId,
            'file_validation',
            $processedFilePath,
            $fileSize
        );

        try {
            // Check if file needs chunking based on duration
            $duration = $this->chunkingService->getAudioDuration($processedFilePath);

            if ($this->chunkingService->needsChunking($duration)) {
                $result = $this->transcribeWithChunking($processedFilePath, $processingId, $duration);
            } else {
                $result = $this->transcribeFile($processedFilePath, $processingId);
            }

            return $result;
        } finally {
            // Clean up temporary S3 download file if we created one
            if ($isS3Disk && file_exists($fullPath)) {
                unlink($fullPath);
            }

            // Clean up compressed file if different from original
            if ($processedFilePath !== $fullPath && file_exists($processedFilePath)) {
                unlink($processedFilePath);
            }
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    /**
     * Transcribe a single audio file — one attempt only.
     *
     * Retry timing and attempt counting are owned entirely by the job layer
     * (TranscribeAudio::$tries + backoff()). This method classifies failures
     * and throws the appropriate typed exception so the job can decide whether
     * to re-queue or fail permanently, without blocking the worker with sleep().
     *
     * @param  string  $filePath  Full path to the audio file
     * @param  string  $processingId  Processing ID for logging
     * @return string The transcribed text
     *
     * @throws NonRetryableTranscriptionException When the error is deterministic (bad key, oversized file)
     * @throws TranscriptionException When the error is transient (rate limit, network, etc.)
     */
    private function transcribeFile(string $filePath, string $processingId = 'unknown'): string
    {
        $apiStartTime = microtime(true);

        try {
            $this->logger->logProcessingStep(
                $processingId,
                'openai_api_call',
                'started',
                ['file' => basename($filePath)]
            );

            $response = OpenAI::audio()->transcribe([
                'file' => fopen($filePath, 'r'),
                'model' => 'gpt-4o-transcribe',
                'response_format' => 'text',
                'language' => 'en',
                'prompt' => (string) config('media-processing.transcription.prompts.sermon'),
            ]);

            $apiTime = microtime(true) - $apiStartTime;

            $this->logger->logApiCall(
                $processingId,
                'OpenAI',
                'audio/transcriptions',
                $apiTime,
                200,
                null,
                ['model' => 'gpt-4o-transcribe']
            );

            $transcript = $response->text;

            if (empty($transcript)) {
                throw new TranscriptionException('Received empty transcript from OpenAI API');
            }

            if (! $this->validateTranscript($transcript)) {
                throw new TranscriptionException('Transcript validation failed - content appears invalid');
            }

            $this->logger->logProcessingStep(
                $processingId,
                'transcription_validation',
                'completed',
                [
                    'transcript_length' => strlen($transcript),
                    'word_count' => str_word_count($transcript),
                ]
            );

            return $this->formatter->formatAsMarkdown($transcript);
        } catch (ErrorException $e) {
            $apiTime = microtime(true) - $apiStartTime;

            $this->logger->logApiCall(
                $processingId,
                'OpenAI',
                'audio/transcriptions',
                $apiTime,
                $e->getCode(),
                $e->getMessage(),
                []
            );

            $this->logger->logProcessingStep(
                $processingId,
                'audio_transcription',
                'failed',
                ['error' => $e->getMessage(), 'file' => basename($filePath)]
            );

            if ($this->isNonRetryableError($e)) {
                throw new NonRetryableTranscriptionException(
                    "Transcription failed (non-retryable): {$e->getMessage()}",
                    0,
                    $e
                );
            }

            throw new TranscriptionException(
                "Transcription failed (retryable API error): {$e->getMessage()}",
                0,
                $e
            );
        } catch (TransporterException $e) {
            $apiTime = microtime(true) - $apiStartTime;

            $this->logger->logApiCall(
                $processingId,
                'OpenAI',
                'audio/transcriptions',
                $apiTime,
                0,
                $e->getMessage(),
                ['error_type' => 'network']
            );

            $this->logger->logProcessingStep(
                $processingId,
                'audio_transcription',
                'failed',
                ['error' => $e->getMessage(), 'file' => basename($filePath)]
            );

            throw new TranscriptionException(
                "Transcription failed (network error): {$e->getMessage()}",
                0,
                $e
            );
        } catch (TranscriptionException $e) {
            // Re-throw typed exceptions from validation checks above
            $this->logger->logProcessingStep(
                $processingId,
                'audio_transcription',
                'failed',
                ['error' => $e->getMessage(), 'file' => basename($filePath)]
            );

            throw $e;
        } catch (Exception $e) {
            $this->logger->logError(
                $processingId,
                'transcription_attempt',
                $e,
                ['file' => basename($filePath)]
            );

            throw new TranscriptionException(
                "Transcription failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Validate audio file size and compress if needed for transcription service
     *
     * @param  string  $filePath  Full path to the audio file
     * @param  string  $processingId  Processing ID for logging
     * @return string Path to the processed file (original or compressed)
     *
     * @throws Exception When compression fails or file is still too large after compression
     */
    private function validateAndCompressIfNeeded(string $filePath, string $processingId): string
    {
        $fileSize = filesize($filePath);
        $maxSize = config('media-processing.audio_extraction.transcription_optimized.max_file_size');

        // If file is within limits, return original path
        if ($fileSize <= $maxSize) {
            return $filePath;
        }

        $sizeMB = round($fileSize / 1024 / 1024, 1);
        $maxSizeMB = round($maxSize / 1024 / 1024, 1);

        Log::info('Audio file exceeds transcription limit, attempting compression', $this->sanitizeArrayForLog([
            'processing_id' => $processingId,
            'original_size_mb' => $sizeMB,
            'max_size_mb' => $maxSizeMB,
            'file_path' => $filePath,
        ]));

        try {
            // Attempt to compress the file
            $compressedPath = $this->chunkingService->compressAudioForTranscription($filePath, $processingId);

            $compressedSize = filesize($compressedPath);
            $compressedSizeMB = round($compressedSize / 1024 / 1024, 1);

            // Check if compressed file is now within limits
            if ($compressedSize > $maxSize) {
                // Clean up failed compression attempt
                if (file_exists($compressedPath)) {
                    unlink($compressedPath);
                }

                throw new TranscriptionException(
                    "Audio file still too large after compression: {$compressedSizeMB}MB (limit: {$maxSizeMB}MB). ".
                    'Consider using a shorter audio segment or manual compression.'
                );
            }

            Log::info('Audio compression successful', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'original_size_mb' => $sizeMB,
                'compressed_size_mb' => $compressedSizeMB,
                'compression_ratio' => round(($fileSize - $compressedSize) / $fileSize * 100, 1),
            ]));

            return $compressedPath;

        } catch (Exception $e) {
            Log::error('Audio compression failed', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'original_size_mb' => $sizeMB,
                'error' => $e->getMessage(),
            ]));

            throw new TranscriptionException(
                "Audio file too large ({$sizeMB}MB) and compression failed: {$e->getMessage()}. ".
                "File size limit is {$maxSizeMB}MB. Please ensure audio is compressed for transcription before processing.",
                0,
                $e
            );
        }
    }

    /**
     * Validate transcript content quality
     *
     * @param  string  $transcript  The transcript text
     * @return bool True if transcript appears valid
     */
    private function validateTranscript(string $transcript): bool
    {
        // Basic validation checks
        $transcript = trim($transcript);

        // Must have minimum length
        if (strlen($transcript) < 50) {
            Log::warning('Transcript too short', $this->sanitizeArrayForLog([
                'length' => strlen($transcript),
            ]));

            return false;
        }

        // Must have reasonable word count
        $wordCount = str_word_count($transcript);
        if ($wordCount < 10) {
            Log::warning('Transcript has too few words', $this->sanitizeArrayForLog([
                'word_count' => $wordCount,
            ]));

            return false;
        }

        // Check for common transcription errors or gibberish
        $gibberishPatterns = [
            '/^[^a-zA-Z]*$/', // Only non-alphabetic characters
            '/(.)\1{10,}/', // Repeated character 10+ times
        ];

        foreach ($gibberishPatterns as $pattern) {
            if (preg_match($pattern, $transcript)) {
                Log::warning('Transcript appears to contain gibberish', $this->sanitizeArrayForLog([
                    'pattern' => $pattern,
                ]));

                return false;
            }
        }

        return true;
    }

    /**
     * Check if an error should not be retried
     *
     * @param  ErrorException  $exception  The OpenAI API exception
     * @return bool True if error should not be retried
     */
    private function isNonRetryableError(ErrorException $exception): bool
    {
        $nonRetryableCodes = [
            400, // Bad Request - invalid file format
            401, // Unauthorized - invalid API key
            413, // Payload Too Large - file too big
        ];

        return in_array($exception->getStatusCode(), $nonRetryableCodes);
    }

    /**
     * Transcribe audio file using chunking for long files
     *
     * @param  string  $filePath  Full path to the audio file
     * @param  string  $processingId  Processing ID for logging
     * @param  float  $duration  Total duration in seconds
     * @return string The complete transcribed text
     *
     * @throws Exception When chunking or transcription fails
     */
    private function transcribeWithChunking(string $filePath, string $processingId, float $duration): string
    {
        $chunkDurationSeconds = $this->chunkingService->getChunkDurationMinutes() * 60;
        $overlapSeconds = $this->chunkingService->getChunkOverlapSeconds();

        $this->logger->logProcessingStep(
            $processingId,
            'audio_chunking',
            'started',
            [
                'total_duration' => $duration,
                'chunk_duration' => $chunkDurationSeconds,
                'overlap_duration' => $overlapSeconds,
            ]
        );

        try {
            // Create chunks
            $chunks = $this->chunkingService->createAudioChunks($filePath, $processingId, $duration);

            // Transcribe each chunk
            $transcripts = [];
            $totalChunks = count($chunks);

            foreach ($chunks as $index => $chunkPath) {
                $chunkNumber = $index + 1;

                $this->logger->logProcessingStep(
                    $processingId,
                    'chunk_transcription',
                    'started',
                    [
                        'chunk' => $chunkNumber,
                        'total_chunks' => $totalChunks,
                        'chunk_file' => basename($chunkPath),
                    ]
                );

                $chunkTranscript = $this->transcribeFile($chunkPath, $processingId);
                $transcripts[] = [
                    'index' => $index,
                    'transcript' => $chunkTranscript,
                    'start_time' => $index * ($chunkDurationSeconds - $overlapSeconds),
                ];

                $this->logger->logProcessingStep(
                    $processingId,
                    'chunk_transcription',
                    'completed',
                    [
                        'chunk' => $chunkNumber,
                        'transcript_length' => strlen($chunkTranscript),
                    ]
                );
            }

            // Clean up chunk files
            $this->chunkingService->cleanupChunkFiles($chunks, $processingId);

            // Reassemble transcripts with overlap handling
            $reassembled = $this->chunkingService->reassembleTranscripts($transcripts, $processingId);
            $finalTranscript = $this->formatter->formatAsMarkdown($reassembled);

            $this->logger->logProcessingStep(
                $processingId,
                'audio_chunking',
                'completed',
                [
                    'total_chunks' => $totalChunks,
                    'final_transcript_length' => strlen($finalTranscript),
                ]
            );

            return $finalTranscript;

        } catch (Exception $e) {
            $this->logger->logError(
                $processingId,
                'audio_chunking_failed',
                $e,
                ['duration' => $duration]
            );
            throw new TranscriptionException('Chunked transcription failed: '.$e->getMessage(), 0, $e);
        }
    }
}
