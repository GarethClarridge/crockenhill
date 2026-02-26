<?php

namespace App\Services;

use App\Contracts\TranscriptionServiceInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MockTranscriptionService implements TranscriptionServiceInterface
{
    private const TRANSCRIPT_DIRECTORY = 'transcripts';

    private const DEFAULT_TRANSCRIPT_PATH = 'transcripts/sermon_7.md';

    public function __construct(
        private readonly SermonProcessingLogger $logger
    ) {}

    /**
     * Mock transcription that returns the sermon_7.md content
     *
     * @param  string  $audioFilePath  Path to the audio file (not actually used)
     * @param  string  $processingId  Processing ID for logging
     * @return string The mock transcribed text from sermon_7.md
     *
     * @throws Exception When mock transcript cannot be loaded
     */
    public function transcribe(string $audioFilePath, string $processingId = 'unknown', ?string $disk = null): string
    {
        $startTime = microtime(true);

        $this->logger->logProcessingStep(
            $processingId,
            'mock_transcription',
            'started',
            [
                'file_path' => $audioFilePath,
                'mock' => true,
            ]
        );

        // Try to load the default transcript
        $transcript = $this->loadDefaultTranscript();

        if (empty($transcript)) {
            throw new Exception('Failed to load mock transcript content');
        }

        $processingTime = microtime(true) - $startTime;

        $this->logger->logProcessingStep(
            $processingId,
            'mock_transcription',
            'completed',
            [
                'transcript_length' => strlen($transcript),
                'word_count' => str_word_count($transcript),
                'processing_time' => $processingTime,
                'mock' => true,
            ]
        );

        Log::info('Mock transcription completed', [
            'processing_id' => $processingId,
            'audio_file' => basename($audioFilePath),
            'transcript_length' => strlen($transcript),
            'processing_time' => $processingTime,
            'mock' => true,
        ]);

        return $transcript;
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
    public function storeTranscript(int $sermonId, string $transcript): string
    {
        $filename = $this->getTranscriptFilename($sermonId);
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$filename;
        $disk = $this->transcriptDisk();
        $storage = Storage::disk($disk);

        try {
            // Ensure transcript directory exists
            if (! $storage->exists(self::TRANSCRIPT_DIRECTORY)) {
                $storage->makeDirectory(self::TRANSCRIPT_DIRECTORY);
                Log::info('Created transcript directory', [
                    'directory' => self::TRANSCRIPT_DIRECTORY,
                    'disk' => $disk,
                ]);
            }

            // Store the transcript
            $success = $storage->put($filePath, $transcript);

            if (! $success) {
                throw new Exception('Failed to write transcript to storage');
            }

            Log::info('Mock transcript stored successfully', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'size' => strlen($transcript),
                'mock' => true,
            ]);

            return $filePath;
        } catch (Exception $e) {
            Log::error('Failed to store mock transcript', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'mock' => true,
            ]);
            throw new Exception("Failed to store transcript for sermon {$sermonId}: ".$e->getMessage());
        }
    }

    /**
     * Retrieve transcript content from storage
     *
     * @param  int  $sermonId  The sermon ID
     * @return string|null The transcript content or null if not found
     */
    public function getTranscript(int $sermonId): ?string
    {
        $filename = $this->getTranscriptFilename($sermonId);
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$filename;
        $disk = $this->transcriptDisk();
        $storage = Storage::disk($disk);

        if (! $storage->exists($filePath)) {
            Log::info('Mock transcript file not found', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'mock' => true,
            ]);

            return null;
        }

        try {
            $content = $storage->get($filePath);
            Log::info('Mock transcript retrieved successfully', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'size' => strlen($content),
                'mock' => true,
            ]);

            return $content;
        } catch (Exception $e) {
            Log::error('Failed to retrieve mock transcript', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'mock' => true,
            ]);

            return null;
        }
    }

    /**
     * Check if transcript exists for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return bool True if transcript exists
     */
    public function transcriptExists(int $sermonId): bool
    {
        $filename = $this->getTranscriptFilename($sermonId);
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$filename;

        return Storage::disk($this->transcriptDisk())->exists($filePath);
    }

    /**
     * Delete transcript file for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return bool True if deleted or didn't exist
     */
    public function deleteTranscript(int $sermonId): bool
    {
        $filename = $this->getTranscriptFilename($sermonId);
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$filename;
        $disk = $this->transcriptDisk();
        $storage = Storage::disk($disk);

        if (! $storage->exists($filePath)) {
            Log::info('Mock transcript file does not exist, nothing to delete', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'mock' => true,
            ]);

            return true;
        }

        try {
            $success = $storage->delete($filePath);

            if ($success) {
                Log::info('Mock transcript deleted successfully', [
                    'sermon_id' => $sermonId,
                    'file_path' => $filePath,
                    'disk' => $disk,
                    'mock' => true,
                ]);
            } else {
                Log::warning('Failed to delete mock transcript file', [
                    'sermon_id' => $sermonId,
                    'file_path' => $filePath,
                    'disk' => $disk,
                    'mock' => true,
                ]);
            }

            return $success;
        } catch (Exception $e) {
            Log::error('Error deleting mock transcript', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'mock' => true,
            ]);

            return false;
        }
    }

    /**
     * Clean up transcript files on processing failure
     *
     * @param  int  $sermonId  The sermon ID
     */
    public function cleanupOnFailure(int $sermonId): void
    {
        Log::info('Cleaning up mock transcript files after processing failure', [
            'sermon_id' => $sermonId,
            'mock' => true,
        ]);

        $this->deleteTranscript($sermonId);
    }

    /**
     * Get the full transcript file path for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return string The full file path
     */
    public function getTranscriptPath(int $sermonId): string
    {
        $filename = $this->getTranscriptFilename($sermonId);

        return self::TRANSCRIPT_DIRECTORY.'/'.$filename;
    }

    /**
     * Load the default transcript content from sermon_7.md
     *
     * @return string The default transcript content
     *
     * @throws Exception When default transcript cannot be loaded
     */
    private function loadDefaultTranscript(): string
    {
        $storage = Storage::disk($this->transcriptDisk());

        if (! $storage->exists(self::DEFAULT_TRANSCRIPT_PATH)) {
            throw new Exception('Default transcript file not found: '.self::DEFAULT_TRANSCRIPT_PATH);
        }

        try {
            $content = $storage->get(self::DEFAULT_TRANSCRIPT_PATH);

            if (empty(trim($content))) {
                throw new Exception('Default transcript file is empty');
            }

            Log::info('Loaded default transcript for mock transcription', [
                'source_file' => self::DEFAULT_TRANSCRIPT_PATH,
                'disk' => $this->transcriptDisk(),
                'content_length' => strlen($content),
                'word_count' => str_word_count($content),
                'mock' => true,
            ]);

            return $content;
        } catch (Exception $e) {
            Log::error('Failed to load default transcript', [
                'source_file' => self::DEFAULT_TRANSCRIPT_PATH,
                'disk' => $this->transcriptDisk(),
                'error' => $e->getMessage(),
                'mock' => true,
            ]);
            throw new Exception('Failed to load default transcript: '.$e->getMessage());
        }
    }

    private function transcriptDisk(): string
    {
        return (string) config('media-processing.storage.transcript_disk', config('media-processing.storage.sermon_disk', config('filesystems.default', 'public')));
    }

    /**
     * Get the transcript filename for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return string The filename
     */
    private function getTranscriptFilename(int $sermonId): string
    {
        return "sermon_{$sermonId}.md";
    }
}
