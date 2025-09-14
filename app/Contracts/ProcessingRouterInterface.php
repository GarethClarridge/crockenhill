<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\ProcessingResult;
use Illuminate\Http\UploadedFile;

/**
 * ProcessingRouterInterface - Interface for processing routing operations
 *
 * Defines the contract for processing router following dependency injection best practices.
 * Part of Phase 4 refactoring to improve dependency injection and testability.
 */
interface ProcessingRouterInterface
{
    /**
     * Route processing based on type using strategy pattern
     */
    public function route(string $type, UploadedFile $file, array $options = []): ProcessingResult;

    /**
     * Get supported processing types from strategy registry
     */
    public function getSupportedTypes(): array;

    /**
     * Validate file for specific processing type using strategy
     */
    public function validateFileForType(UploadedFile $file, string $type): array;

    /**
     * Get routing statistics for monitoring
     */
    public function getRoutingStatistics(): array;
}