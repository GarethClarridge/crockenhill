<?php

namespace App\Services;

use App\Data\SermonCreationOptions;
use App\Enums\TitleGenerationStrategy;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SermonCreationService
{
    /**
     * Create a sermon record with all necessary metadata
     */
    public function createSermon(
        MediaProcessingLog $processingLog,
        SermonCreationOptions $options
    ): Sermon {
        // Extract date using cascading strategy
        $sermonDate = $options->date ?? $this->extractDate(
            $processingLog,
            $options->originalFilename
        );

        // Extract service type using cascading strategy
        $service = $options->service ?? $this->extractServiceType(
            $processingLog,
            $options->originalFilename
        );

        // Generate title based on strategy
        $title = $this->generateTitle(
            $options->titleStrategy,
            [
                'ai_analysis' => $options->aiAnalysis,
                'filename' => $options->originalFilename,
                'custom_title' => $options->customTitle,
                'processing_log' => $processingLog,
                'date' => $sermonDate,
                'service' => $service,
            ]
        );

        // Generate unique slug
        $slug = $this->generateUniqueSlug($title, $sermonDate);

        // Build sermon data
        $sermonData = [
            'title' => $title,
            'audio_file_path' => $options->audioFilePath,
            'filetype' => pathinfo($options->originalFilename, PATHINFO_EXTENSION) ?: 'mp3',
            'date' => $sermonDate,
            'service' => $service,
            'slug' => $slug,
            'preacher' => $options->preacher ?? 'Mark Drury',
            'source_type' => $options->sourceType,
        ];

        // Add optional fields if present
        if ($options->videoFilePath) {
            $sermonData['video_file_path'] = $options->videoFilePath;
        }

        if ($options->transcriptFilePath) {
            $sermonData['transcript_file_path'] = $options->transcriptFilePath;
        }

        if ($options->livestreamProcessingId) {
            $sermonData['livestream_processing_id'] = $options->livestreamProcessingId;
        }

        // Add AI analysis fields if available
        if ($options->aiAnalysis) {
            if (isset($options->aiAnalysis['series'])) {
                $sermonData['series'] = $options->aiAnalysis['series'];
            }
            if (isset($options->aiAnalysis['reference'])) {
                $sermonData['reference'] = $options->aiAnalysis['reference'];
            }
            if (isset($options->aiAnalysis['points'])) {
                $sermonData['points'] = json_encode($options->aiAnalysis['points']);
            }
        }

        return Sermon::create($sermonData);
    }

    /**
     * Extract sermon date using cascading strategy
     * 1. Processing metadata (client-provided or video metadata)
     * 2. Filename parsing
     * 3. Current date
     */
    public function extractDate(
        MediaProcessingLog $processingLog,
        string $filename
    ): string {
        // Strategy 1: Check if date was extracted from video/audio metadata
        $processingMetadata = $processingLog->processing_metadata;

        if (is_array($processingMetadata) && isset($processingMetadata['extracted_date'])) {
            $extractedDate = $processingMetadata['extracted_date'];
            Log::info('SermonCreationService: Using date extracted from file metadata', [
                'processing_id' => $processingLog->processing_id,
                'extracted_date' => $extractedDate,
                'extraction_method' => $processingMetadata['date_extraction_method'] ?? 'unknown',
            ]);

            return $extractedDate;
        }

        // Strategy 2: Fall back to filename parsing
        $filenameDate = $this->extractDateFromFilename($filename);

        Log::info('SermonCreationService: Using date extracted from filename', [
            'processing_id' => $processingLog->processing_id,
            'filename' => $filename,
            'extracted_date' => $filenameDate,
        ]);

        return $filenameDate;
    }

    /**
     * Extract service type using cascading strategy
     * 1. Processing metadata (file timestamp-based detection)
     * 2. Filename parsing
     * 3. Default to morning
     */
    public function extractServiceType(
        MediaProcessingLog $processingLog,
        string $filename
    ): string {
        // Strategy 1: Check if service was extracted from file metadata
        $processingMetadata = $processingLog->processing_metadata;

        if (is_array($processingMetadata) && isset($processingMetadata['extracted_service'])) {
            $extractedService = $processingMetadata['extracted_service'];
            Log::info('SermonCreationService: Using service extracted from file metadata', [
                'processing_id' => $processingLog->processing_id,
                'extracted_service' => $extractedService,
                'extraction_method' => $processingMetadata['service_extraction_method'] ?? 'unknown',
            ]);

            return $extractedService;
        }

        // Strategy 2: Fall back to filename parsing
        $filename = strtolower($filename);

        if (str_contains($filename, 'evening')) {
            Log::info('SermonCreationService: Detected evening service from filename', [
                'processing_id' => $processingLog->processing_id,
                'filename' => $filename,
            ]);

            return 'evening';
        }

        if (str_contains($filename, 'morning')) {
            Log::info('SermonCreationService: Detected morning service from filename', [
                'processing_id' => $processingLog->processing_id,
                'filename' => $filename,
            ]);

            return 'morning';
        }

        // Strategy 3: Default to morning if no service pattern found
        Log::info('SermonCreationService: Defaulting to morning service', [
            'processing_id' => $processingLog->processing_id,
            'filename' => $filename,
        ]);

        return 'morning';
    }

    /**
     * Generate a unique URL slug for the sermon
     */
    public function generateUniqueSlug(string $baseTitle, ?string $date = null): string
    {
        $baseSlug = Str::slug($baseTitle);
        $slug = $baseSlug;
        $counter = 1;

        // Ensure slug is unique
        while (Sermon::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Generate sermon title using specified strategy
     */
    public function generateTitle(
        TitleGenerationStrategy $strategy,
        array $context
    ): string {
        return match ($strategy) {
            TitleGenerationStrategy::AI_WITH_FALLBACK => $this->generateTitleAiWithFallback($context),
            TitleGenerationStrategy::FILENAME_ONLY => $this->generateTitleFromFilename($context),
            TitleGenerationStrategy::CUSTOM => $context['custom_title'] ?? $this->generateTitleFromFilename($context),
        };
    }

    /**
     * Generate title using AI analysis first, fallback to filename
     */
    private function generateTitleAiWithFallback(array $context): string
    {
        $aiAnalysis = $context['ai_analysis'] ?? null;

        // Use AI-generated title if available
        if ($aiAnalysis && ! empty($aiAnalysis['title'])) {
            return Str::limit($aiAnalysis['title'], 100, '');
        }

        // Fall back to filename processing
        return $this->generateTitleFromFilename($context);
    }

    /**
     * Generate title from filename only
     */
    private function generateTitleFromFilename(array $context): string
    {
        $filename = $context['filename'] ?? '';
        /** @var MediaProcessingLog|null $processingLog */
        $processingLog = $context['processing_log'] ?? null;

        if (empty($filename)) {
            return 'Sermon - '.now()->format('F j, Y');
        }

        $baseFilename = pathinfo($filename, PATHINFO_FILENAME);

        // Remove common date patterns
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $baseFilename);
        $title = preg_replace('/\d{1,2}[-_]\d{1,2}[-_]\d{4}/', '', $title ?? '');

        // Remove common sermon-related words and clean up
        $title = preg_replace('/\b(sermon|message|service|am|pm)\b/i', '', $title ?? '');
        $title = preg_replace('/[-_]+/', ' ', $title ?? '');
        $title = trim($title ?? '');

        // If title is empty or too short, use a default
        if (empty($title) || strlen($title) < 3) {
            // Try to build from context
            $date = $context['date'] ?? $this->extractDateFromFilename($filename);

            // Extract service type - only if processing log is available
            if ($processingLog) {
                $service = $context['service'] ?? $this->extractServiceType($processingLog, $filename);
            } else {
                // Fallback: simple filename parsing when no processing log
                $service = $context['service'] ?? (str_contains(strtolower($filename), 'evening') ? 'evening' : 'morning');
            }

            $serviceLabel = $service === 'evening' ? 'Evening' : 'Morning';

            // Use processing log created_at if available, otherwise parse date
            if ($processingLog) {
                $title = $serviceLabel.' Sermon - '.$processingLog->created_at->format('F j, Y');
            } else {
                $title = $serviceLabel.' Sermon - '.date('F j, Y', strtotime($date));
            }
        }

        // Capitalize words properly
        $title = Str::title($title);

        // Ensure it's not too long
        return Str::limit($title, 100, '');
    }

    /**
     * Extract date from filename
     */
    private function extractDateFromFilename(string $filename): string
    {
        // Try YYYY-MM-DD or YYYY_MM_DD format
        if (preg_match('/(\d{4})[-_](\d{1,2})[-_](\d{1,2})/', $filename, $matches)) {
            return $matches[1].'-'.str_pad($matches[2], 2, '0', STR_PAD_LEFT).'-'.str_pad($matches[3], 2, '0', STR_PAD_LEFT);
        }

        // Try DD-MM-YYYY or DD_MM_YYYY format
        if (preg_match('/(\d{1,2})[-_](\d{1,2})[-_](\d{4})/', $filename, $matches)) {
            return $matches[3].'-'.str_pad($matches[2], 2, '0', STR_PAD_LEFT).'-'.str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        }

        // Fallback to current date if no date pattern found
        return now()->format('Y-m-d');
    }
}
