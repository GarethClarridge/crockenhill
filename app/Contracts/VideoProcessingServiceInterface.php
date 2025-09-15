<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\LivestreamProcessingResult;
use App\Data\LivestreamProcessingStatus;
use App\Services\ProcessingResult;
use Illuminate\Http\UploadedFile;

/**
 * VideoProcessingServiceInterface - Interface for video processing operations
 *
 * Defines the contract for video processing service following dependency injection best practices.
 * Part of Phase 4 refactoring to improve dependency injection and testability.
 */
interface VideoProcessingServiceInterface
{
    /**
     * Process livestream video with segmentation
     */
    public function processLivestream(UploadedFile $videoFile): ProcessingResult;

    /**
     * Process video with segmentation (for livestream videos)
     */
    public function processWithSegmentation(UploadedFile $videoFile): ProcessingResult;

    /**
     * Get processing status for a livestream processing operation
     */
    public function getProcessingStatus(string $processingId): LivestreamProcessingStatus;

    /**
     * Get processing result for completed operation
     */
    public function getProcessingResult(string $processingId): LivestreamProcessingResult;

    /**
     * Retry failed processing operation
     */
    public function retryProcessing(string $processingId): LivestreamProcessingResult;

    /**
     * Cancel ongoing processing operation
     */
    public function cancelProcessing(string $processingId): bool;

    /**
     * Update processing status
     */
    public function updateProcessingStatus(string $processingId, string $status): void;

    /**
     * Get processing summary statistics
     */
    public function getProcessingSummary(): array;
}
