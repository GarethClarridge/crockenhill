<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\ProcessingResult;
use Illuminate\Http\UploadedFile;

/**
 * ProcessingStrategyInterface - Strategy pattern for different processing types
 *
 * Defines the contract for processing strategies as outlined in the refactoring plan.
 * Allows for unified processing while maintaining type-specific logic.
 */
interface ProcessingStrategyInterface
{
    /**
     * Check if this strategy supports the given processing type
     */
    public function supports(string $type): bool;

    /**
     * Process the uploaded file using this strategy
     */
    public function process(UploadedFile $file, array $options = []): ProcessingResult;

    /**
     * Validate file for this processing type
     */
    public function validateFile(UploadedFile $file): array;

    /**
     * Get configuration for this processing type
     */
    public function getConfiguration(): array;
}