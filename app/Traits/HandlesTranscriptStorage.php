<?php

declare(strict_types=1);

namespace App\Traits;

use App\Services\Media\Audio\TranscriptStorageService;

/**
 * Trait HandlesTranscriptStorage
 *
 * Provides default implementations for TranscriptionServiceInterface storage methods
 * by delegating them to an injected TranscriptStorageService.
 *
 * Using this trait allows transcription services (e.g., AudioTranscriptionService,
 * LocalWhisperTranscriptionService, MockTranscriptionService) to satisfy the storage-related
 * methods of TranscriptionServiceInterface without duplicating the delegation boilerplate.
 *
 * @property-read \App\Services\Media\Audio\TranscriptStorageService $storageService The injected transcript storage service dependency
 */
trait HandlesTranscriptStorage
{
    /**
     * Store transcript content (delegates to TranscriptStorageService)
     *
     * @param  int  $sermonId  The sermon ID
     * @param  string  $transcript  The transcript content
     * @return string The file path where transcript was stored
     *
     * @throws \Exception When storage fails
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
}
