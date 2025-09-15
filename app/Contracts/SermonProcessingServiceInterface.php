<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\ProcessingResult;
use App\Services\ProcessingStatusResult;
use Illuminate\Http\UploadedFile;

/**
 * SermonProcessingServiceInterface - Interface for sermon processing operations
 *
 * Defines the contract for sermon processing service following dependency injection best practices.
 * Part of Phase 4 refactoring to improve dependency injection and testability.
 */
interface SermonProcessingServiceInterface
{
    /**
     * Process a sermon audio file through the complete automation pipeline
     */
    public function processSermon(UploadedFile $file): ProcessingResult;

    /**
     * Process a sermon audio file from livestream with additional metadata
     */
    public function processSermonAudio(UploadedFile $file, array $livestreamMetadata = []): array;

    /**
     * Get the current processing status for a given processing ID
     */
    public function getProcessingStatus(string $processingId): ProcessingStatusResult;

    /**
     * Cancel processing operation
     */
    public function cancelProcessing(string $processingId): bool;
}
