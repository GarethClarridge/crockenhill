<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * VideoStorageServiceInterface - Handles video file storage operations
 *
 * Defines the contract for video storage operations extracted from VideoProcessingService
 * following the refactoring plan to improve dependency injection and testability.
 */
interface VideoStorageServiceInterface
{
    /**
     * Store uploaded file temporarily for processing
     */
    public function storeTemporary(UploadedFile $file): array;

    /**
     * Move processed file to permanent storage
     */
    public function moveToPermanent(array $uploadResult): string;

    /**
     * Validate storage space requirements
     */
    public function validateStorageSpace(int $requiredBytes): bool;

    /**
     * Clean up temporary files and processing artifacts
     */
    public function cleanup(string $processingId): void;

    /**
     * Store uploaded video file temporarily
     */
    public function storeUploadedVideo(\Illuminate\Http\UploadedFile $file): array;

    /**
     * Clean up temporary files after processing
     */
    public function cleanupTemporaryFiles(array $filePaths): void;
}
