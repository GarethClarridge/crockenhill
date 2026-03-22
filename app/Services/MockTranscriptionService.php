<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TranscriptionServiceInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MockTranscriptionService implements TranscriptionServiceInterface
{
    private const TRANSCRIPT_DIRECTORY = 'transcripts';

    private const MOCK_TRANSCRIPT = <<<'TRANSCRIPT'
Good morning, everyone. Today we're going to be looking at Romans chapter 8, verse 28, and our first point is the scope of God's sovereignty. The apostle Paul writes, "And we know that in all things God works for the good of those who love him, who have been called according to his purpose." Paul is not limiting this promise to comfortable seasons; he includes uncertainty, disappointment, and delay. In every season, the Lord is active, wise, and present with his people.

Our second point is how believers respond when life is hard. We are not called to deny pain, but to bring it honestly before God in prayer while remaining rooted in Scripture. Christian hope is not passive optimism; it is active trust that God can redeem what we cannot yet understand. As we walk through trials, faith grows through obedience, patience, and mutual encouragement in the church.

Our third point is the purpose of this promise in daily mission. When we remember God's faithful providence, we become steadier in service, kinder in speech, and more courageous in witness. We forgive more quickly, we persevere in doing good, and we care for people who are weary. The same grace that sustains us also equips us to strengthen others and point them to Christ.
TRANSCRIPT;

    public function __construct(
        private readonly SermonProcessingLogger $logger
    ) {}

    /**
     * Mock transcription that returns static in-code content
     *
     * @param  string  $audioFilePath  Path to the audio file (not actually used)
     * @param  string  $processingId  Processing ID for logging
     * @return string The mock transcribed text
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

        $transcript = self::MOCK_TRANSCRIPT;

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

            if (! is_string($content)) {
                return null;
            }

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
