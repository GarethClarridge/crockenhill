<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TranscriptionServiceInterface;
use App\Exceptions\TranscriptionException;
use App\Traits\DetectsStorageType;
use App\Traits\HandlesTranscriptStorage;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LocalWhisperTranscriptionService implements TranscriptionServiceInterface
{
    use DetectsStorageType;
    use HandlesTranscriptStorage;

    public function __construct(
        private readonly SermonProcessingLogger $logger,
        private readonly TranscriptStorageService $storageService,
        private readonly AudioChunkingService $chunkingService,
        private readonly TranscriptFormatterService $formatter,
    ) {}

    /**
     * Transcribe audio using a locally running Whisper server.
     *
     * The server must implement the OpenAI-compatible /v1/audio/transcriptions
     * endpoint (e.g. faster-whisper-server). No API key is required.
     *
     * @throws TranscriptionException When transcription fails
     */
    public function transcribe(string $audioFilePath, string $processingId = 'unknown', ?string $disk = null): string
    {
        $diskName = $disk ?? config('media-processing.storage.sermon_disk', 'public');
        $isAbsolutePath = $this->isAbsolutePath($audioFilePath);

        $this->logger->logProcessingStep(
            $processingId,
            'local_whisper_transcription',
            'started',
            ['file_path' => $audioFilePath, 'disk' => $isAbsolutePath ? 'absolute_path' : $diskName]
        );

        if ($isAbsolutePath) {
            if (! file_exists($audioFilePath)) {
                throw new TranscriptionException("Audio file not found: {$audioFilePath} on disk: absolute_path");
            }

            $fullPath = $audioFilePath;
            $isS3Disk = false;
        } else {
            $fileExists = Storage::disk($diskName)->exists($audioFilePath);

            if (! $fileExists && $diskName !== 'public') {
                $fileExists = Storage::disk('public')->exists($audioFilePath);
                if ($fileExists) {
                    $diskName = 'public';
                }
            }

            if (! $fileExists) {
                throw new TranscriptionException("Audio file not found: {$audioFilePath} on disk: {$diskName}");
            }

            $isS3Disk = $this->isS3Disk($diskName);

            if ($isS3Disk) {
                $fullPath = storage_path('app/temp/'.basename($audioFilePath).'_'.time().'.mp3');
                $this->ensureDirectoryExists(dirname($fullPath));
                $audioStream = Storage::disk($diskName)->readStream($audioFilePath);
                file_put_contents($fullPath, $audioStream);
            } else {
                $fullPath = Storage::disk($diskName)->path($audioFilePath);
            }
        }

        $processedFilePath = $this->validateAndCompressIfNeeded($fullPath, $processingId);

        try {
            $duration = $this->chunkingService->getAudioDuration($processedFilePath);

            if ($this->chunkingService->needsChunking($duration)) {
                return $this->transcribeWithChunking($processedFilePath, $processingId, $duration);
            }

            return $this->transcribeFile($processedFilePath, $processingId);
        } finally {
            if ($isS3Disk && file_exists($fullPath) && $fullPath !== $processedFilePath) {
                unlink($fullPath);
            }

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
     * Send a single audio file to the local Whisper server and return the raw transcript.
     *
     * @throws TranscriptionException When the server call fails or returns invalid content
     */
    private function transcribeFile(string $filePath, string $processingId): string
    {
        $baseUrl = config('media-processing.transcription.local_whisper_url');
        $model = config('media-processing.transcription.local_whisper_model');
        $timeout = config('media-processing.transcription.local_whisper_timeout');

        $this->logger->logProcessingStep(
            $processingId,
            'local_whisper_api_call',
            'started',
            ['file' => basename($filePath), 'model' => $model]
        );

        $apiStartTime = microtime(true);

        try {
            $fileHandle = fopen($filePath, 'r');

            if ($fileHandle === false) {
                throw new TranscriptionException("Could not open audio file for reading: {$filePath}");
            }

            $response = Http::timeout($timeout)
                ->attach('file', $fileHandle, basename($filePath))
                ->post("{$baseUrl}/v1/audio/transcriptions", [
                    'model' => $model,
                    'language' => 'en',
                    'response_format' => 'text',
                    'prompt' => 'The following speech is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.',
                ]);
        } catch (Exception $e) {
            $apiTime = microtime(true) - $apiStartTime;

            $this->logger->logApiCall(
                $processingId,
                'LocalWhisper',
                'audio/transcriptions',
                $apiTime,
                0,
                $e->getMessage(),
                ['error_type' => 'connection']
            );

            throw new TranscriptionException(
                "Local Whisper connection failed: {$e->getMessage()}",
                0,
                $e
            );
        }

        $apiTime = microtime(true) - $apiStartTime;

        if ($response->failed()) {
            $this->logger->logApiCall(
                $processingId,
                'LocalWhisper',
                'audio/transcriptions',
                $apiTime,
                $response->status(),
                $response->body(),
                []
            );

            throw new TranscriptionException(
                "Local Whisper transcription failed: HTTP {$response->status()} — {$response->body()}"
            );
        }

        $this->logger->logApiCall(
            $processingId,
            'LocalWhisper',
            'audio/transcriptions',
            $apiTime,
            200,
            null,
            ['model' => $model]
        );

        $transcript = trim($response->body());

        if (empty($transcript)) {
            throw new TranscriptionException('Local Whisper returned an empty transcript');
        }

        if (! $this->validateTranscript($transcript)) {
            throw new TranscriptionException('Local Whisper transcript validation failed — content appears invalid');
        }

        $this->logger->logProcessingStep(
            $processingId,
            'local_whisper_api_call',
            'completed',
            [
                'transcript_length' => strlen($transcript),
                'word_count' => str_word_count($transcript),
            ]
        );

        return $this->formatter->formatAsMarkdown($transcript);
    }

    /**
     * Transcribe a long audio file by splitting it into chunks first.
     *
     * @throws TranscriptionException When chunking or any chunk transcription fails
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
            $chunks = $this->chunkingService->createAudioChunks($filePath, $processingId, $duration);

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

            $this->chunkingService->cleanupChunkFiles($chunks, $processingId);

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

    /**
     * Compress the audio file if it exceeds the configured size limit.
     *
     * @throws TranscriptionException When compression fails or the file is still too large
     */
    private function validateAndCompressIfNeeded(string $filePath, string $processingId): string
    {
        $fileSize = filesize($filePath);
        $maxSize = config('media-processing.audio_extraction.transcription_optimized.max_file_size');

        if ($fileSize <= $maxSize) {
            return $filePath;
        }

        $sizeMB = round($fileSize / 1024 / 1024, 1);
        $maxSizeMB = round($maxSize / 1024 / 1024, 1);

        Log::info('Audio file exceeds transcription limit, attempting compression', [
            'processing_id' => $processingId,
            'original_size_mb' => $sizeMB,
            'max_size_mb' => $maxSizeMB,
        ]);

        try {
            $compressedPath = $this->chunkingService->compressAudioForTranscription($filePath, $processingId);
            $compressedSize = filesize($compressedPath);
            $compressedSizeMB = round($compressedSize / 1024 / 1024, 1);

            if ($compressedSize > $maxSize) {
                if (file_exists($compressedPath)) {
                    unlink($compressedPath);
                }

                throw new TranscriptionException(
                    "Audio file still too large after compression: {$compressedSizeMB}MB (limit: {$maxSizeMB}MB)."
                );
            }

            return $compressedPath;
        } catch (TranscriptionException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new TranscriptionException(
                "Audio file too large ({$sizeMB}MB) and compression failed: {$e->getMessage()}.",
                0,
                $e
            );
        }
    }

    private function validateTranscript(string $transcript): bool
    {
        $transcript = trim($transcript);

        if (strlen($transcript) < 50) {
            Log::warning('Local Whisper transcript too short', ['length' => strlen($transcript)]);

            return false;
        }

        if (str_word_count($transcript) < 10) {
            Log::warning('Local Whisper transcript has too few words', ['word_count' => str_word_count($transcript)]);

            return false;
        }

        $gibberishPatterns = [
            '/^[^a-zA-Z]*$/',
            '/(.)\1{10,}/',
        ];

        foreach ($gibberishPatterns as $pattern) {
            if (preg_match($pattern, $transcript)) {
                Log::warning('Local Whisper transcript appears to contain gibberish', ['pattern' => $pattern]);

                return false;
            }
        }

        return true;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            throw new TranscriptionException("Failed to create directory: {$directory}");
        }
    }
}
