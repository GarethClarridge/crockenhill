<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * ProcessingHealthServiceInterface - Health monitoring for processing systems
 *
 * Extracted from SermonProcessingService to follow Single Responsibility Principle.
 * Handles system health checks and processing statistics monitoring.
 */
interface ProcessingHealthServiceInterface
{
    /**
     * Get overall system health status
     */
    public function getSystemHealth(): array;

    /**
     * Check queue processing health
     */
    public function checkQueueHealth(): array;

    /**
     * Check storage system health
     */
    public function checkStorageHealth(): array;

    /**
     * Check processing pipeline health
     */
    public function checkProcessingHealth(): array;

    /**
     * Get processing statistics and metrics
     */
    public function getProcessingStatistics(): array;
}
