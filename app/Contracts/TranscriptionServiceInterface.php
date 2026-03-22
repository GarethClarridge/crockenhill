<?php

declare(strict_types=1);

namespace App\Contracts;

interface TranscriptionServiceInterface
{
    /**
     * Transcribe audio file to text
     *
     * @param  string  $audioFilePath  Path to the audio file
     * @param  string  $processingId  Processing ID for logging
     * @param  string|null  $disk  Optional disk name for file storage
     * @return string The transcribed text
     *
     * @throws \Exception When transcription fails
     */
    public function transcribe(string $audioFilePath, string $processingId = 'unknown', ?string $disk = null): string;

    /**
     * Store transcript to file using sermon ID
     *
     * @param  int  $sermonId  The sermon ID
     * @param  string  $transcript  The transcript content
     * @return string The stored file path
     *
     * @throws \Exception When storage fails
     */
    public function storeTranscript(int $sermonId, string $transcript): string;

    /**
     * Retrieve transcript content from storage
     *
     * @param  int  $sermonId  The sermon ID
     * @return string|null The transcript content or null if not found
     */
    public function getTranscript(int $sermonId): ?string;

    /**
     * Check if transcript exists for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return bool True if transcript exists
     */
    public function transcriptExists(int $sermonId): bool;

    /**
     * Delete transcript file for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return bool True if deleted or didn't exist
     */
    public function deleteTranscript(int $sermonId): bool;

    /**
     * Clean up transcript files on processing failure
     *
     * @param  int  $sermonId  The sermon ID
     */
    public function cleanupOnFailure(int $sermonId): void;

    /**
     * Get the full transcript file path for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return string The full file path
     */
    public function getTranscriptPath(int $sermonId): string;
}
