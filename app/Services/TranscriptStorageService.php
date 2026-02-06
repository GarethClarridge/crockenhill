<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranscriptStorageService
{
    private const TRANSCRIPT_DIRECTORY = 'transcripts';

    /**
     * Store transcript content to file
     *
     * @param  int  $sermonId  The sermon ID
     * @param  string  $transcript  The transcript content
     * @return string The file path where transcript was stored
     *
     * @throws Exception When storage fails
     */
    public function storeTranscript(int $sermonId, string $transcript): string
    {
        $filename = $this->getTranscriptFilename($sermonId);
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$filename;

        try {
            // Ensure transcript directory exists
            if (! Storage::exists(self::TRANSCRIPT_DIRECTORY)) {
                Storage::makeDirectory(self::TRANSCRIPT_DIRECTORY);
                Log::info('Created transcript directory', ['directory' => self::TRANSCRIPT_DIRECTORY]);
            }

            // Store the transcript
            $success = Storage::put($filePath, $transcript);

            if (! $success) {
                throw new Exception('Failed to write transcript to storage');
            }

            Log::info('Transcript stored successfully', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'size' => strlen($transcript),
            ]);

            return $filePath;
        } catch (Exception $e) {
            Log::error('Failed to store transcript', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
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

        if (! Storage::exists($filePath)) {
            Log::info('Transcript file not found', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
            ]);

            return null;
        }

        try {
            $content = Storage::get($filePath);
            Log::info('Transcript retrieved successfully', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'size' => strlen($content),
            ]);

            return $content;
        } catch (Exception $e) {
            Log::error('Failed to retrieve transcript', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
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

        return Storage::exists($filePath);
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

        if (! Storage::exists($filePath)) {
            Log::info('Transcript file does not exist, nothing to delete', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
            ]);

            return true;
        }

        try {
            $success = Storage::delete($filePath);

            if ($success) {
                Log::info('Transcript deleted successfully', [
                    'sermon_id' => $sermonId,
                    'file_path' => $filePath,
                ]);
            } else {
                Log::warning('Failed to delete transcript file', [
                    'sermon_id' => $sermonId,
                    'file_path' => $filePath,
                ]);
            }

            return $success;
        } catch (Exception $e) {
            Log::error('Error deleting transcript', [
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
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
        Log::info('Cleaning up transcript files after processing failure', ['sermon_id' => $sermonId]);

        $this->deleteTranscript($sermonId);
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
}
