<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;

class AudioTranscriptionService
{
  private const MAX_RETRIES = 3;
  private const RETRY_DELAY_BASE = 2; // seconds
  private const CHUNK_SIZE = 25 * 1024 * 1024; // 25MB - OpenAI Whisper limit
  private const TRANSCRIPT_DIRECTORY = 'transcripts';

  protected SermonProcessingLogger $logger;

  public function __construct(SermonProcessingLogger $logger)
  {
    $this->logger = $logger;

    // Verify OpenAI API key is configured
    if (empty(config('sermon-processing.transcription.openai_api_key'))) {
      throw new Exception('OpenAI API key not configured for transcription service');
    }
  }

  /**
   * Transcribe audio file to text using OpenAI Whisper API
   *
   * @param string $audioFilePath Path to the audio file
   * @param string $processingId Processing ID for logging
   * @return string The transcribed text
   * @throws Exception When transcription fails
   */
  public function transcribe(string $audioFilePath, string $processingId = 'unknown'): string
  {
    $startTime = microtime(true);

    $this->logger->logProcessingStep(
      $processingId,
      'audio_transcription',
      'started',
      ['file_path' => $audioFilePath]
    );

    // Validate file exists and is readable
    if (!Storage::exists($audioFilePath)) {
      throw new Exception("Audio file not found: {$audioFilePath}");
    }

    $fullPath = Storage::path($audioFilePath);
    $fileSize = filesize($fullPath);

    $this->logger->logFileOperation(
      $processingId,
      'file_validation',
      $audioFilePath,
      $fileSize
    );

    // Check if file needs chunking
    if ($fileSize > self::CHUNK_SIZE) {
      return $this->transcribeWithChunking($fullPath, $processingId);
    }

    return $this->transcribeFile($fullPath, $processingId);
  }

  /**
   * Transcribe a single audio file with retry logic
   *
   * @param string $filePath Full path to the audio file
   * @param string $processingId Processing ID for logging
   * @return string The transcribed text
   * @throws Exception When all retry attempts fail
   */
  private function transcribeFile(string $filePath, string $processingId = 'unknown'): string
  {
    $attempt = 0;
    $lastException = null;

    while ($attempt < self::MAX_RETRIES) {
      try {
        $attempt++;
        $apiStartTime = microtime(true);

        $this->logger->logProcessingStep(
          $processingId,
          'openai_api_call',
          'started',
          ['attempt' => $attempt, 'file' => basename($filePath)]
        );

        $response = OpenAI::audio()->transcribe([
          'file' => fopen($filePath, 'r'),
          'model' => 'whisper-1',
          'response_format' => 'text',
          'language' => 'en', // Assuming English sermons
        ]);

        $apiTime = microtime(true) - $apiStartTime;

        $this->logger->logApiCall(
          $processingId,
          'OpenAI',
          'audio/transcriptions',
          $apiTime,
          200,
          null,
          ['attempt' => $attempt, 'model' => 'whisper-1']
        );

        $transcript = $response->text ?? '';

        if (empty($transcript)) {
          throw new Exception('Received empty transcript from OpenAI API');
        }

        // Validate transcript quality
        if (!$this->validateTranscript($transcript)) {
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
    $errorMessage = $lastException ? $lastException->getMessage() : 'Unknown error';

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

    throw new Exception("Transcription failed after {$attempt} attempts: {$errorMessage}");
  }

  /**
   * Handle large files by chunking (placeholder for future implementation)
   *
   * @param string $filePath Full path to the audio file
   * @param string $processingId Processing ID for logging
   * @return string The transcribed text
   * @throws Exception When chunking is not yet implemented
   */
  private function transcribeWithChunking(string $filePath, string $processingId = 'unknown'): string
  {
    // For now, reject files that are too large
    // Future implementation could split audio files into chunks
    $sizeMB = round(filesize($filePath) / 1024 / 1024, 2);

    $this->logger->logProcessingStep(
      $processingId,
      'file_chunking',
      'failed',
      [
        'file_size_mb' => $sizeMB,
        'max_size_mb' => 25,
        'reason' => 'chunking_not_implemented'
      ]
    );

    throw new Exception("File too large for transcription: {$sizeMB}MB (max 25MB). Chunking not yet implemented.");
  }

  /**
   * Validate transcript content quality
   *
   * @param string $transcript The transcript text
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
   * @param string $transcript Raw transcript text
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
      if (!empty(trim($formattedParagraph))) {
        $formattedParagraphs[] = $formattedParagraph;
      }
    }

    // Join paragraphs with proper spacing
    return implode("\n\n", $formattedParagraphs);
  }

  /**
   * Split transcript into sentences while preserving natural speech patterns
   *
   * @param string $transcript Raw transcript text
   * @return array Array of sentences
   */
  private function splitIntoSentences(string $transcript): array
  {
    // Split on sentence endings, but be careful with abbreviations and Bible references
    $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $transcript);

    // Clean up each sentence
    $sentences = array_map('trim', $sentences);
    $sentences = array_filter($sentences, function ($sentence) {
      return !empty($sentence) && strlen($sentence) > 3;
    });

    return array_values($sentences);
  }

  /**
   * Group sentences into logical paragraphs
   *
   * @param array $sentences Array of sentences
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
    if (!empty($currentParagraph)) {
      $paragraphs[] = implode(' ', $currentParagraph);
    }

    return $paragraphs;
  }

  /**
   * Determine if a new paragraph should start after this sentence
   *
   * @param string $sentence Current sentence
   * @param int $sentenceCount Number of sentences in current paragraph
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
   * @param string $paragraph Raw paragraph text
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
   * @param string $text Text to clean up
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

    // Ensure sentences end with proper punctuation
    if (!preg_match('/[.!?]$/', trim($text))) {
      $text = trim($text) . '.';
    }

    return trim($text);
  }

  /**
   * Check if an error should not be retried
   *
   * @param ErrorException $exception The OpenAI API exception
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
   * @param int $sermonId The sermon ID
   * @param string $transcript The transcript content
   * @return string The stored file path
   * @throws Exception When storage fails
   */
  public function storeTranscript(int $sermonId, string $transcript): string
  {
    $filename = $this->getTranscriptFilename($sermonId);
    $filePath = self::TRANSCRIPT_DIRECTORY . '/' . $filename;

    try {
      // Ensure transcript directory exists
      if (!Storage::exists(self::TRANSCRIPT_DIRECTORY)) {
        Storage::makeDirectory(self::TRANSCRIPT_DIRECTORY);
        Log::info('Created transcript directory', ['directory' => self::TRANSCRIPT_DIRECTORY]);
      }

      // Store the transcript
      $success = Storage::put($filePath, $transcript);

      if (!$success) {
        throw new Exception('Failed to write transcript to storage');
      }

      Log::info('Transcript stored successfully', [
        'sermon_id' => $sermonId,
        'file_path' => $filePath,
        'size' => strlen($transcript)
      ]);

      return $filePath;
    } catch (Exception $e) {
      Log::error('Failed to store transcript', [
        'sermon_id' => $sermonId,
        'file_path' => $filePath,
        'error' => $e->getMessage()
      ]);
      throw new Exception("Failed to store transcript for sermon {$sermonId}: " . $e->getMessage());
    }
  }

  /**
   * Retrieve transcript content from storage
   *
   * @param int $sermonId The sermon ID
   * @return string|null The transcript content or null if not found
   */
  public function getTranscript(int $sermonId): ?string
  {
    $filename = $this->getTranscriptFilename($sermonId);
    $filePath = self::TRANSCRIPT_DIRECTORY . '/' . $filename;

    if (!Storage::exists($filePath)) {
      Log::info('Transcript file not found', [
        'sermon_id' => $sermonId,
        'file_path' => $filePath
      ]);
      return null;
    }

    try {
      $content = Storage::get($filePath);
      Log::info('Transcript retrieved successfully', [
        'sermon_id' => $sermonId,
        'file_path' => $filePath,
        'size' => strlen($content)
      ]);
      return $content;
    } catch (Exception $e) {
      Log::error('Failed to retrieve transcript', [
        'sermon_id' => $sermonId,
        'file_path' => $filePath,
        'error' => $e->getMessage()
      ]);
      return null;
    }
  }

  /**
   * Check if transcript exists for a sermon
   *
   * @param int $sermonId The sermon ID
   * @return bool True if transcript exists
   */
  public function transcriptExists(int $sermonId): bool
  {
    $filename = $this->getTranscriptFilename($sermonId);
    $filePath = self::TRANSCRIPT_DIRECTORY . '/' . $filename;

    return Storage::exists($filePath);
  }

  /**
   * Delete transcript file for a sermon
   *
   * @param int $sermonId The sermon ID
   * @return bool True if deleted or didn't exist
   */
  public function deleteTranscript(int $sermonId): bool
  {
    $filename = $this->getTranscriptFilename($sermonId);
    $filePath = self::TRANSCRIPT_DIRECTORY . '/' . $filename;

    if (!Storage::exists($filePath)) {
      Log::info('Transcript file does not exist, nothing to delete', [
        'sermon_id' => $sermonId,
        'file_path' => $filePath
      ]);
      return true;
    }

    try {
      $success = Storage::delete($filePath);

      if ($success) {
        Log::info('Transcript deleted successfully', [
          'sermon_id' => $sermonId,
          'file_path' => $filePath
        ]);
      } else {
        Log::warning('Failed to delete transcript file', [
          'sermon_id' => $sermonId,
          'file_path' => $filePath
        ]);
      }

      return $success;
    } catch (Exception $e) {
      Log::error('Error deleting transcript', [
        'sermon_id' => $sermonId,
        'file_path' => $filePath,
        'error' => $e->getMessage()
      ]);
      return false;
    }
  }

  /**
   * Clean up transcript files on processing failure
   *
   * @param int $sermonId The sermon ID
   * @return void
   */
  public function cleanupOnFailure(int $sermonId): void
  {
    Log::info('Cleaning up transcript files after processing failure', ['sermon_id' => $sermonId]);

    $this->deleteTranscript($sermonId);
  }

  /**
   * Get the transcript filename for a sermon
   *
   * @param int $sermonId The sermon ID
   * @return string The filename
   */
  private function getTranscriptFilename(int $sermonId): string
  {
    return "sermon_{$sermonId}.md";
  }

  /**
   * Get the full transcript file path for a sermon
   *
   * @param int $sermonId The sermon ID
   * @return string The full file path
   */
  public function getTranscriptPath(int $sermonId): string
  {
    $filename = $this->getTranscriptFilename($sermonId);
    return self::TRANSCRIPT_DIRECTORY . '/' . $filename;
  }
}
