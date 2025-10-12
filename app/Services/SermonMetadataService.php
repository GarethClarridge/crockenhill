<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SermonMetadataServiceInterface;
use App\Data\SermonMetadata;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * SermonMetadataService - Handles sermon metadata operations
 *
 * Extracted from SermonProcessingService to follow Single Responsibility Principle.
 * Manages metadata extraction, fallback generation, and slug creation.
 */
class SermonMetadataService implements SermonMetadataServiceInterface
{
    /**
     * Extract metadata from filename using various patterns
     */
    public function extractFromFilename(string $filename): SermonMetadata
    {
        // Remove file extension for easier parsing
        $nameWithoutExtension = pathinfo($filename, PATHINFO_FILENAME);

        // Extract date from filename
        $date = $this->extractDateFromFilename($nameWithoutExtension);

        // Determine service type from filename
        $service = $this->determineServiceFromFilename($nameWithoutExtension);

        return new SermonMetadata(
            date: $date,
            service: $service,
            filename: basename($filename),
            originalName: $filename
        );
    }

    /**
     * Generate fallback metadata when AI processing fails
     */
    public function generateFallbackData(Sermon $sermon, MediaProcessingLog $processingLog): array
    {
        // Generate basic title if current title is generic
        $title = $sermon->title;
        if (
            empty($title) ||
            str_contains(strtolower($title), 'untitled') ||
            str_contains(strtolower($title), 'sermon -')
        ) {
            $title = $this->generateFallbackTitle($sermon, $processingLog);
        }

        $slug = $this->generateUniqueSlug($title, $sermon->id);

        return [
            'title' => $title,
            'slug' => $slug,
            'series' => null, // No series identification in fallback
            'reference' => null, // No Bible passage extraction in fallback
            'points' => ['Main Message'], // Simple fallback points
        ];
    }

    /**
     * Generate unique slug for sermon avoiding conflicts
     */
    public function generateUniqueSlug(string $title, int $excludeId): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        // Ensure slug is unique (excluding current sermon)
        while (Sermon::where('slug', $slug)->where('id', '!=', $excludeId)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Extract date from filename using various patterns
     */
    private function extractDateFromFilename(string $filename): Carbon
    {
        // Pattern 1: YYYY-MM-DD format
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $filename, $matches)) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // Pattern 2: DD-MM-YYYY format
        if (preg_match('/(\d{1,2})-(\d{1,2})-(\d{4})/', $filename, $matches)) {
            return Carbon::createFromDate((int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        // Pattern 3: YYYY_MM_DD format
        if (preg_match('/(\d{4})_(\d{1,2})_(\d{1,2})/', $filename, $matches)) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // Pattern 4: DD_MM_YYYY format
        if (preg_match('/(\d{1,2})_(\d{1,2})_(\d{4})/', $filename, $matches)) {
            return Carbon::createFromDate((int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        // Fallback: use current date
        return Carbon::today();
    }

    /**
     * Determine service type from filename content
     */
    private function determineServiceFromFilename(string $filename): SermonService
    {
        $lowerFilename = strtolower($filename);

        // Look for evening service indicators
        if (str_contains($lowerFilename, 'evening') ||
            str_contains($lowerFilename, 'pm') ||
            str_contains($lowerFilename, '6pm') ||
            str_contains($lowerFilename, '18:') ||
            str_contains($lowerFilename, 'night')) {
            return SermonService::EVENING;
        }

        // Look for morning service indicators
        if (str_contains($lowerFilename, 'morning') ||
            str_contains($lowerFilename, 'am') ||
            str_contains($lowerFilename, '10am') ||
            str_contains($lowerFilename, '11am') ||
            str_contains($lowerFilename, '10:') ||
            str_contains($lowerFilename, '11:')) {
            return SermonService::MORNING;
        }

        // Default to morning service
        return SermonService::MORNING;
    }

    /**
     * Generate fallback title when existing title is inadequate
     */
    private function generateFallbackTitle(Sermon $sermon, MediaProcessingLog $processingLog): string
    {
        // Use sermon date and service
        $dateStr = $sermon->date->format('F j, Y');
        $service = $sermon->service ? $sermon->service->label() : 'Service';

        return "{$dateStr} {$service}";
    }
}
