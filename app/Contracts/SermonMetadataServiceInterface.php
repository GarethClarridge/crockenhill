<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\SermonMetadata;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;

/**
 * SermonMetadataServiceInterface - Handles sermon metadata extraction and generation
 *
 * Extracted from SermonProcessingService to follow Single Responsibility Principle.
 * Manages all metadata-related operations for sermon processing.
 */
interface SermonMetadataServiceInterface
{
    /**
     * Extract metadata from filename using various patterns
     */
    public function extractFromFilename(string $filename): SermonMetadata;

    /**
     * Generate fallback metadata when AI processing fails
     */
    public function generateFallbackData(Sermon $sermon, MediaProcessingLog $log): array;

    /**
     * Generate unique slug for sermon avoiding conflicts
     */
    public function generateUniqueSlug(string $title, int $excludeId): string;
}
