<?php

namespace App\Services;

use App\Contracts\TranscriptionServiceInterface;
use App\Exceptions\TranscriptionException;
use Exception;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;

class AudioTranscriptionService implements TranscriptionServiceInterface
{
    private const MAX_RETRIES = 3;

    private const RETRY_DELAY_BASE = 2; // seconds

    // Chunking configuration for long audio files
    private const CHUNK_DURATION_MINUTES = 6; // 6 minutes per chunk to stay well under timeout

    private const CHUNK_OVERLAP_SECONDS = 15; // 15 seconds overlap to prevent word cutoffs

    private const MIN_DURATION_FOR_CHUNKING = 420; // 7 minutes - only chunk files longer than this

    public function __construct(
        private readonly MediaProcessingLogger $logger,
        private readonly TranscriptStorageService $storageService,
        private readonly BritishEnglishConverter $britishEnglishConverter
    ) {}

    /**
     * Verify OpenAI API key is configured before making API calls
     *
     * @throws Exception When OpenAI API key is not configured
     */
    private function ensureApiKeyConfigured(): void
    {
        if (empty(config('media-processing.transcription.openai_api_key'))) {
            throw new Exception('OpenAI API key not configured for transcription service');
        }
    }

    /**
     * Check if the disk is S3-compatible (DigitalOcean Spaces, AWS S3, etc.)
     */
    private function isS3Disk(string $diskName): bool
    {
        $diskConfig = config("filesystems.disks.{$diskName}");

        return isset($diskConfig['driver']) && $diskConfig['driver'] === 's3';
    }

    /**
     * Ensure directory exists (for local operations only)
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true)) {
                throw new \Exception("Failed to create directory: {$directory}");
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
     * @throws Exception When transcription fails
     */
    public function transcribe(string $audioFilePath, string $processingId = 'unknown', ?string $disk = null): string
    {
        // Verify API key is configured before proceeding
        $this->ensureApiKeyConfigured();

        // Use provided disk or default to sermon disk
        $diskName = $disk ?? config('media-processing.storage.sermon_disk', 'public');

        $startTime = microtime(true);

        $this->logger->logProcessingStep(
            $processingId,
            'audio_transcription',
            'started',
            ['file_path' => $audioFilePath, 'disk' => $diskName]
        );

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
        $videoStorageService = app(VideoStorageService::class);
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

        // Validate file size and compress if needed
        $processedFilePath = $this->validateAndCompressIfNeeded($fullPath, $processingId);

        $fileSize = filesize($processedFilePath);

        $this->logger->logFileOperation(
            $processingId,
            'file_validation',
            $processedFilePath,
            $fileSize
        );

        try {
            // Check if file needs chunking based on duration
            $duration = $this->getAudioDuration($processedFilePath);

            if ($duration > self::MIN_DURATION_FOR_CHUNKING) {
                $result = $this->transcribeWithChunking($processedFilePath, $processingId, $duration);
            } else {
                $result = $this->transcribeFile($processedFilePath, $processingId);
            }

            return $result;
        } finally {
            // Clean up temporary S3 download file if we created one
            if ($isS3Disk && file_exists($fullPath) && $fullPath !== $processedFilePath) {
                unlink($fullPath);
            }

            // Clean up compressed file if different from original
            if ($processedFilePath !== $fullPath && file_exists($processedFilePath)) {
                unlink($processedFilePath);
            }
        }
    }

    /**
     * Transcribe a single audio file with retry logic
     *
     * @param  string  $filePath  Full path to the audio file
     * @param  string  $processingId  Processing ID for logging
     * @return string The transcribed text
     *
     * @throws Exception When all retry attempts fail
     */
    private function transcribeFile(string $filePath, string $processingId = 'unknown'): string
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $apiStartTime = microtime(true);
            try {

                $this->logger->logProcessingStep(
                    $processingId,
                    'openai_api_call',
                    'started',
                    ['attempt' => $attempt, 'file' => basename($filePath)]
                );

                $response = OpenAI::audio()->transcribe([
                    'file' => fopen($filePath, 'r'),
                    'model' => 'gpt-4o-transcribe',
                    'response_format' => 'text',
                    'language' => 'en',
                    'prompt' => 'The following speech is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.',
                ]);

                $apiTime = microtime(true) - $apiStartTime;

                $this->logger->logApiCall(
                    $processingId,
                    'OpenAI',
                    'audio/transcriptions',
                    $apiTime,
                    200,
                    null,
                    ['attempt' => $attempt, 'model' => 'gpt-4o-transcribe']
                );

                $transcript = $response->text ?? '';

                if (empty($transcript)) {
                    throw new Exception('Received empty transcript from OpenAI API');
                }

                // Validate transcript quality
                if (! $this->validateTranscript($transcript)) {
                    throw new Exception('Transcript validation failed - content appears invalid');
                }

                $this->logger->logProcessingStep(
                    $processingId,
                    'transcription_validation',
                    'completed',
                    [
                        'transcript_length' => strlen($transcript),
                        'word_count' => str_word_count($transcript),
                        'attempt' => $attempt,
                    ]
                );

                return $this->formatAsMarkdown($transcript);
            } catch (ErrorException $e) {
                $lastException = $e;
                $apiTime = microtime(true) - $apiStartTime;

                $this->logger->logApiCall(
                    $processingId,
                    'OpenAI',
                    'audio/transcriptions',
                    $apiTime,
                    $e->getCode(),
                    $e->getMessage(),
                    ['attempt' => $attempt]
                );

                // Don't retry on certain errors
                if ($this->isNonRetryableError($e)) {
                    break;
                }
            } catch (TransporterException $e) {
                $lastException = $e;
                $apiTime = microtime(true) - $apiStartTime;

                $this->logger->logApiCall(
                    $processingId,
                    'OpenAI',
                    'audio/transcriptions',
                    $apiTime,
                    0,
                    $e->getMessage(),
                    ['attempt' => $attempt, 'error_type' => 'network']
                );
            } catch (Exception $e) {
                $lastException = $e;

                $this->logger->logError(
                    $processingId,
                    'transcription_attempt',
                    $e,
                    ['attempt' => $attempt, 'file' => basename($filePath)]
                );
            }

            // Wait before retry with exponential backoff
            if ($attempt < self::MAX_RETRIES) {
                $delay = self::RETRY_DELAY_BASE ** $attempt;
                $this->logger->logProcessingStep(
                    $processingId,
                    'retry_delay',
                    'waiting',
                    ['delay_seconds' => $delay, 'next_attempt' => $attempt + 1]
                );
                sleep($delay);
            }
        }

        // All attempts failed
        $errorMessage = $lastException->getMessage();

        $this->logger->logProcessingStep(
            $processingId,
            'audio_transcription',
            'failed',
            [
                'total_attempts' => $attempt,
                'final_error' => $errorMessage,
                'file' => basename($filePath),
            ]
        );

        throw new TranscriptionException("Transcription failed after {$attempt} attempts: {$errorMessage}", 0, $lastException);
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

        Log::info('Audio file exceeds transcription limit, attempting compression', [
            'processing_id' => $processingId,
            'original_size_mb' => $sizeMB,
            'max_size_mb' => $maxSizeMB,
            'file_path' => $filePath,
        ]);

        try {
            // Attempt to compress the file
            $compressedPath = $this->compressAudioForTranscription($filePath, $processingId);

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

            Log::info('Audio compression successful', [
                'processing_id' => $processingId,
                'original_size_mb' => $sizeMB,
                'compressed_size_mb' => $compressedSizeMB,
                'compression_ratio' => round(($fileSize - $compressedSize) / $fileSize * 100, 1),
            ]);

            return $compressedPath;

        } catch (Exception $e) {
            Log::error('Audio compression failed', [
                'processing_id' => $processingId,
                'original_size_mb' => $sizeMB,
                'error' => $e->getMessage(),
            ]);

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
            Log::warning('Transcript too short', ['length' => strlen($transcript)]);

            return false;
        }

        // Must have reasonable word count
        $wordCount = str_word_count($transcript);
        if ($wordCount < 10) {
            Log::warning('Transcript has too few words', ['word_count' => $wordCount]);

            return false;
        }

        // Check for common transcription errors or gibberish
        $gibberishPatterns = [
            '/^[^a-zA-Z]*$/', // Only non-alphabetic characters
            '/(.)\1{10,}/', // Repeated character 10+ times
        ];

        foreach ($gibberishPatterns as $pattern) {
            if (preg_match($pattern, $transcript)) {
                Log::warning('Transcript appears to contain gibberish', ['pattern' => $pattern]);

                return false;
            }
        }

        return true;
    }

    /**
     * Format transcript as readable Markdown
     *
     * @param  string  $transcript  Raw transcript text
     * @return string Formatted Markdown content
     */
    private function formatAsMarkdown(string $transcript): string
    {
        // Clean up the transcript
        $transcript = trim($transcript);

        // Split into sentences for better processing
        $sentences = $this->splitIntoSentences($transcript);

        // Group sentences into paragraphs based on natural breaks
        $paragraphs = $this->groupSentencesIntoParagraphs($sentences);

        // Format each paragraph
        $formattedParagraphs = [];
        foreach ($paragraphs as $paragraph) {
            $formattedParagraph = $this->formatParagraph($paragraph);
            if (! empty(trim($formattedParagraph))) {
                $formattedParagraphs[] = $formattedParagraph;
            }
        }

        // Join paragraphs with proper spacing
        return implode("\n\n", $formattedParagraphs);
    }

    /**
     * Split transcript into sentences while preserving natural speech patterns
     *
     * @param  string  $transcript  Raw transcript text
     * @return array Array of sentences
     */
    private function splitIntoSentences(string $transcript): array
    {
        // Split on sentence endings, but be careful with abbreviations and Bible references
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $transcript);

        // Clean up each sentence
        $sentences = array_map('trim', $sentences);
        $sentences = array_filter($sentences, function ($sentence) {
            return ! empty($sentence) && strlen($sentence) > 3;
        });

        return array_values($sentences);
    }

    /**
     * Group sentences into logical paragraphs
     *
     * @param  array  $sentences  Array of sentences
     * @return array Array of paragraph strings
     */
    private function groupSentencesIntoParagraphs(array $sentences): array
    {
        if (empty($sentences)) {
            return [];
        }

        $paragraphs = [];
        $currentParagraph = [];
        $sentenceCount = 0;

        foreach ($sentences as $sentence) {
            $currentParagraph[] = $sentence;
            $sentenceCount++;

            // Determine if we should start a new paragraph
            $shouldBreak = $this->shouldStartNewParagraph($sentence, $sentenceCount);

            if ($shouldBreak || $sentenceCount >= 8) { // Max 8 sentences per paragraph
                $paragraphs[] = implode(' ', $currentParagraph);
                $currentParagraph = [];
                $sentenceCount = 0;
            }
        }

        // Add any remaining sentences
        if (! empty($currentParagraph)) {
            $paragraphs[] = implode(' ', $currentParagraph);
        }

        return $paragraphs;
    }

    /**
     * Determine if a new paragraph should start after this sentence
     *
     * @param  string  $sentence  Current sentence
     * @param  int  $sentenceCount  Number of sentences in current paragraph
     * @return bool True if new paragraph should start
     */
    private function shouldStartNewParagraph(string $sentence, int $sentenceCount): bool
    {
        // Always break after at least 2 sentences
        if ($sentenceCount < 2) {
            return false;
        }

        // Break on topic transitions (common sermon phrases)
        $topicTransitions = [
            '/^(Now|So|Well|But|And so|Let me|I want to|Tonight|This morning|This evening)/i',
            '/^(Firstly|Secondly|Thirdly|Finally|In conclusion|To conclude)/i',
            '/^(Turn with me|Let\'s turn|Look at|Notice)/i',
            '/^(The first|The second|The third|The next)/i',
        ];

        foreach ($topicTransitions as $pattern) {
            if (preg_match($pattern, $sentence)) {
                return true;
            }
        }

        // Break on Bible references at start of sentence
        if (preg_match('/^[A-Z][a-z]+ \d+/', $sentence)) {
            return true;
        }

        return false;
    }

    /**
     * Format a single paragraph with enhanced readability
     *
     * @param  string  $paragraph  Raw paragraph text
     * @return string Formatted paragraph
     */
    private function formatParagraph(string $paragraph): string
    {
        $paragraph = trim($paragraph);

        if (empty($paragraph)) {
            return '';
        }

        // Clean up spacing and punctuation
        $paragraph = $this->cleanupPunctuation($paragraph);

        return $paragraph;
    }

    /**
     * Clean up punctuation and spacing issues common in transcripts
     *
     * @param  string  $text  Text to clean up
     * @return string Cleaned text
     */
    private function cleanupPunctuation(string $text): string
    {
        // Fix multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);

        // Fix spacing around punctuation
        $text = preg_replace('/\s+([,.!?;:])/', '$1', $text);
        $text = preg_replace('/([,.!?;:])\s*([a-zA-Z])/', '$1 $2', $text);

        // Fix common transcription issues
        $text = str_replace([' ,', ' .', ' !', ' ?'], [',', '.', '!', '?'], $text);

        // Apply British English spelling corrections
        $text = $this->applyBritishEnglishSpelling($text);

        // Ensure sentences end with proper punctuation
        if (! preg_match('/[.!?]$/', trim($text))) {
            $text = trim($text).'.';
        }

        return trim($text);
    }

    /**
     * Apply British English spelling corrections to transcript text
     *
     * @param  string  $text  Text to correct
     * @return string Text with British English spelling
     */
    private function applyBritishEnglishSpelling(string $text): string
    {
        return $this->britishEnglishConverter->convert($text);
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

        return in_array($exception->getCode(), $nonRetryableCodes);
    }

    /**
     * Store transcript to file using sermon ID
     *
     * @param  int  $sermonId  The sermon ID
     * @param  string  $transcript  The transcript content
     * @return string The stored file path
     *
     * @throws Exception When storage fails
     */
    /**
     * Store transcript content (delegates to TranscriptStorageService)
     *
     * @param  int  $sermonId  The sermon ID
     * @param  string  $transcript  The transcript content
     * @return string The file path where transcript was stored
     *
     * @throws Exception When storage fails
     */
    public function storeTranscript(int $sermonId, string $transcript): string
    {
        return $this->storageService->storeTranscript($sermonId, $transcript);
    }

    /**
     * Retrieve transcript content (delegates to TranscriptStorageService)
     *
     * @param  int  $sermonId  The sermon ID
     * @return string|null The transcript content or null if not found
     */
    public function getTranscript(int $sermonId): ?string
    {
        return $this->storageService->getTranscript($sermonId);
    }

    /**
     * Check if transcript exists (delegates to TranscriptStorageService)
     *
     * @param  int  $sermonId  The sermon ID
     * @return bool True if transcript exists
     */
    public function transcriptExists(int $sermonId): bool
    {
        return $this->storageService->transcriptExists($sermonId);
    }

    /**
     * Delete transcript file (delegates to TranscriptStorageService)
     *
     * @param  int  $sermonId  The sermon ID
     * @return bool True if deleted or didn't exist
     */
    public function deleteTranscript(int $sermonId): bool
    {
        return $this->storageService->deleteTranscript($sermonId);
    }

    /**
     * Clean up transcript files on failure (delegates to TranscriptStorageService)
     *
     * @param  int  $sermonId  The sermon ID
     */
    public function cleanupOnFailure(int $sermonId): void
    {
        $this->storageService->cleanupOnFailure($sermonId);
    }

    /**
     * Get the full transcript file path (delegates to TranscriptStorageService)
     *
     * @param  int  $sermonId  The sermon ID
     * @return string The full file path
     */
    public function getTranscriptPath(int $sermonId): string
    {
        return $this->storageService->getTranscriptPath($sermonId);
    }

    /**
     * Get audio duration in seconds using FFprobe
     *
     * @param  string  $filePath  Full path to the audio file
     * @return float Duration in seconds
     *
     * @throws Exception When duration cannot be determined
     */
    private function getAudioDuration(string $filePath): float
    {
        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
                'timeout' => 60,
                'ffmpeg.threads' => 1,
            ]);

            $audio = $ffmpeg->open($filePath);
            $duration = $audio->getStreams()->first()->get('duration');

            return (float) $duration;
        } catch (Exception $e) {
            throw new TranscriptionException('Failed to get audio duration: '.$e->getMessage(), 0, $e);
        }
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
        $this->logger->logProcessingStep(
            $processingId,
            'audio_chunking',
            'started',
            [
                'total_duration' => $duration,
                'chunk_duration' => self::CHUNK_DURATION_MINUTES * 60,
                'overlap_duration' => self::CHUNK_OVERLAP_SECONDS,
            ]
        );

        try {
            // Create chunks
            $chunks = $this->createAudioChunks($filePath, $processingId, $duration);

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
                    'start_time' => $index * (self::CHUNK_DURATION_MINUTES * 60 - self::CHUNK_OVERLAP_SECONDS),
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
            $this->cleanupChunkFiles($chunks, $processingId);

            // Reassemble transcripts with overlap handling
            $finalTranscript = $this->reassembleTranscripts($transcripts, $processingId);

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
     * Create audio chunks from the original file
     *
     * @param  string  $filePath  Full path to the original audio file
     * @param  string  $processingId  Processing ID for logging
     * @param  float  $duration  Total duration in seconds
     * @return array Array of chunk file paths
     *
     * @throws Exception When chunk creation fails
     */
    private function createAudioChunks(string $filePath, string $processingId, float $duration): array
    {
        $chunkDurationSeconds = self::CHUNK_DURATION_MINUTES * 60;
        $overlapSeconds = self::CHUNK_OVERLAP_SECONDS;

        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
            'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            'timeout' => 300,
            'ffmpeg.threads' => 1,
        ]);

        $audio = $ffmpeg->open($filePath);
        $chunks = [];
        $chunkIndex = 0;
        $currentTime = 0;

        while ($currentTime < $duration) {
            // Calculate chunk boundaries
            $startTime = max(0, $currentTime - ($chunkIndex > 0 ? $overlapSeconds : 0));
            $endTime = min($duration, $currentTime + $chunkDurationSeconds);
            $actualDuration = $endTime - $startTime;

            // Skip chunks that are too short
            if ($actualDuration < 30) {
                break;
            }

            // Create temporary chunk file
            $chunkFilename = "chunk_{$processingId}_{$chunkIndex}.mp3";
            $chunkPath = storage_path("app/temp/{$chunkFilename}");

            // Ensure temp directory exists
            $tempDir = dirname($chunkPath);
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            try {
                // Extract chunk with optimized settings for transcription
                $format = new Mp3;
                $format->setAudioKiloBitrate(48) // Match transcription-optimized bitrate
                    ->setAudioChannels(1); // Mono

                $audio->filters()->clip(\FFMpeg\Coordinate\TimeCode::fromSeconds($startTime),
                    \FFMpeg\Coordinate\TimeCode::fromSeconds($actualDuration));
                $audio->save($format, $chunkPath);

                $chunks[] = $chunkPath;

                $this->logger->logProcessingStep(
                    $processingId,
                    'chunk_creation',
                    'completed',
                    [
                        'chunk_index' => $chunkIndex,
                        'start_time' => $startTime,
                        'duration' => $actualDuration,
                        'file_size' => filesize($chunkPath),
                        'chunk_path' => $chunkPath,
                    ]
                );

            } catch (Exception $e) {
                throw new TranscriptionException("Failed to create chunk {$chunkIndex}: ".$e->getMessage(), 0, $e);
            }

            $chunkIndex++;
            $currentTime += ($chunkDurationSeconds - $overlapSeconds);
        }

        return $chunks;
    }

    /**
     * Reassemble transcripts from chunks with overlap deduplication
     *
     * @param  array  $transcripts  Array of transcript data with indices and content
     * @param  string  $processingId  Processing ID for logging
     * @return string The reassembled transcript
     */
    private function reassembleTranscripts(array $transcripts, string $processingId): string
    {
        if (empty($transcripts)) {
            return '';
        }

        // Sort by index to ensure correct order
        usort($transcripts, fn ($a, $b) => $a['index'] <=> $b['index']);

        $reassembled = '';
        $previousTranscript = '';

        foreach ($transcripts as $i => $transcriptData) {
            $transcript = trim($transcriptData['transcript']);

            if ($i === 0) {
                // First chunk - use as-is
                $reassembled = $transcript;
            } else {
                // Subsequent chunks - remove overlap with previous chunk
                $deduplicated = $this->removeOverlapFromTranscript($transcript, $previousTranscript);
                $reassembled .= "\n\n".$deduplicated;
            }

            $previousTranscript = $transcript;
        }

        $this->logger->logProcessingStep(
            $processingId,
            'transcript_reassembly',
            'completed',
            [
                'chunk_count' => count($transcripts),
                'final_length' => strlen($reassembled),
            ]
        );

        return $this->formatAsMarkdown(trim($reassembled));
    }

    /**
     * Remove overlapping content from the beginning of a transcript
     *
     * @param  string  $currentTranscript  Current chunk transcript
     * @param  string  $previousTranscript  Previous chunk transcript
     * @return string Transcript with overlap removed
     */
    private function removeOverlapFromTranscript(string $currentTranscript, string $previousTranscript): string
    {
        if (empty($previousTranscript) || empty($currentTranscript)) {
            return $currentTranscript;
        }

        // Get last few sentences from previous transcript
        $previousSentences = $this->splitIntoSentences($previousTranscript);
        $currentSentences = $this->splitIntoSentences($currentTranscript);

        if (empty($previousSentences) || empty($currentSentences)) {
            return $currentTranscript;
        }

        // Look for overlap by comparing last sentences of previous with first sentences of current
        $maxOverlapSentences = min(3, count($previousSentences), count($currentSentences));
        $overlapFound = 0;

        for ($i = 1; $i <= $maxOverlapSentences; $i++) {
            $previousEnd = array_slice($previousSentences, -$i);
            $currentStart = array_slice($currentSentences, 0, $i);

            // Compare sentences with some tolerance for transcription variations
            if ($this->sentencesMatch($previousEnd, $currentStart)) {
                $overlapFound = $i;
            }
        }

        // Remove overlapping sentences from current transcript
        if ($overlapFound > 0) {
            $remainingSentences = array_slice($currentSentences, $overlapFound);

            return implode(' ', $remainingSentences);
        }

        return $currentTranscript;
    }

    /**
     * Check if two arrays of sentences match with some tolerance
     *
     * @param  array  $sentences1  First set of sentences
     * @param  array  $sentences2  Second set of sentences
     * @return bool True if sentences match sufficiently
     */
    private function sentencesMatch(array $sentences1, array $sentences2): bool
    {
        if (count($sentences1) !== count($sentences2)) {
            return false;
        }

        foreach ($sentences1 as $i => $sentence1) {
            $sentence2 = $sentences2[$i];

            // Normalize sentences for comparison
            $norm1 = $this->normalizeSentenceForComparison($sentence1);
            $norm2 = $this->normalizeSentenceForComparison($sentence2);

            // Calculate similarity
            $similarity = 0;
            similar_text($norm1, $norm2, $similarity);

            // Require 85% similarity to consider a match
            if ($similarity < 85) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize sentence for comparison by removing punctuation and extra spaces
     *
     * @param  string  $sentence  Sentence to normalize
     * @return string Normalized sentence
     */
    private function normalizeSentenceForComparison(string $sentence): string
    {
        // Remove punctuation and convert to lowercase
        $normalized = preg_replace('/[^\w\s]/', '', strtolower($sentence));
        // Collapse multiple spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * Clean up temporary chunk files
     *
     * @param  array  $chunkPaths  Array of chunk file paths
     * @param  string  $processingId  Processing ID for logging
     */
    private function cleanupChunkFiles(array $chunkPaths, string $processingId): void
    {
        foreach ($chunkPaths as $chunkPath) {
            if (file_exists($chunkPath)) {
                unlink($chunkPath);
            }
        }

        $this->logger->logProcessingStep(
            $processingId,
            'chunk_cleanup',
            'completed',
            ['cleaned_chunks' => count($chunkPaths)]
        );
    }

    /**
     * Compress audio file for transcription service using fallback compression settings
     *
     * @param  string  $inputPath  Path to the input audio file
     * @param  string  $processingId  Processing ID for logging
     * @return string Path to the compressed audio file
     *
     * @throws Exception When compression fails
     */
    private function compressAudioForTranscription(string $inputPath, string $processingId): string
    {
        $fallbackConfig = config('media-processing.audio_extraction.fallback_compression');
        $compressedPath = storage_path('app/temp/'.basename($inputPath, '.mp3').'_compressed_'.time().'.mp3');

        // Ensure temp directory exists
        $tempDir = dirname($compressedPath);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);

            $audio = $ffmpeg->open($inputPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($fallbackConfig['bitrate'] ?? 32);
            $format->setAudioChannels($fallbackConfig['channels'] ?? 1);
            // Note: Sample rate is handled by FFmpeg defaults for speech optimization

            $audio->save($format, $compressedPath);

            if (! file_exists($compressedPath) || filesize($compressedPath) === 0) {
                throw new TranscriptionException('Compressed audio file was not created or is empty');
            }

            $this->logger->logFileOperation(
                $processingId,
                'audio_compression',
                $compressedPath,
                filesize($compressedPath)
            );

            Log::info('Fallback audio compression applied', [
                'processing_id' => $processingId,
                'input_path' => $inputPath,
                'output_path' => $compressedPath,
                'compression_settings' => [
                    'bitrate_kbps' => $fallbackConfig['bitrate'] ?? 32,
                    'channels' => $fallbackConfig['channels'] ?? 1,
                    'purpose' => 'transcription_size_reduction',
                ],
            ]);

            return $compressedPath;

        } catch (\Exception $e) {
            // Clean up failed compression attempt
            if (file_exists($compressedPath)) {
                unlink($compressedPath);
            }

            throw new TranscriptionException("Failed to compress audio for transcription: {$e->getMessage()}", 0, $e);
        }
    }
}
