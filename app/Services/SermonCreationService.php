<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\SermonCreationOptions;
use App\Enums\PreacherSource;
use App\Enums\SermonService;
use App\Enums\TitleGenerationStrategy;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SermonCreationService
{
    public function __construct(
        private readonly PreacherResolutionService $preacherResolutionService,
        private readonly SermonRepository $sermonRepository,
    ) {}

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

        // Generate title based on strategy, with ID3 taking priority
        $title = $this->generateTitle(
            $options->titleStrategy,
            [
                'ai_analysis' => $options->aiAnalysis,
                'filename' => $options->originalFilename,
                'custom_title' => $options->customTitle,
                'id3_title' => $options->id3Title,
                'processing_log' => $processingLog,
                'date' => $sermonDate,
                'service' => $service,
            ]
        );

        // Generate unique slug
        $slug = $this->sermonRepository->generateUniqueSlug($title);

        [
            'preacher_name' => $preacherName,
            'preacher_model' => $preacherModel,
            'preacher_source' => $preacherSource,
            'preacher_confidence' => $preacherConfidence,
            'needs_review' => $needsReview,
        ] = $this->resolvePreacherAssignment($options);

        // Build sermon data
        $sermonData = [
            'title' => $title,
            'audio_file_path' => $options->audioFilePath,
            'filetype' => pathinfo($options->originalFilename, PATHINFO_EXTENSION) ?: 'mp3',
            'date' => $sermonDate,
            'service' => $service,
            'content_type' => $options->contentType,
            'slug' => $slug,
            'preacher' => $preacherModel->name,
            'preacher_id' => $preacherModel->id,
            'preacher_source' => $preacherSource,
            'preacher_confidence' => $preacherConfidence,
            'needs_preacher_review' => $needsReview,
            'source_type' => $options->sourceType,
            'duration' => $options->duration,
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

        // Add series with cascading priority: ID3 → AI
        if ($options->id3Series) {
            $sermonData['series'] = $options->id3Series;
        } elseif ($options->aiAnalysis && isset($options->aiAnalysis['series'])) {
            $sermonData['series'] = $options->aiAnalysis['series'];
        }

        // Add reference with cascading priority: ID3 → AI
        if ($options->id3Reference) {
            $sermonData['reference'] = $options->id3Reference;
        } elseif ($options->aiAnalysis && isset($options->aiAnalysis['reference'])) {
            $sermonData['reference'] = $options->aiAnalysis['reference'];
        }

        // Add AI analysis points (ID3 tags don't typically contain sermon points)
        if ($options->aiAnalysis && isset($options->aiAnalysis['points'])) {
            $sermonData['points'] = $options->aiAnalysis['points'];
        }

        return Sermon::query()->create($sermonData);
    }

    /**
     * @return array{
     *     preacher_name:string,
     *     preacher_model:Preacher,
     *     preacher_source:PreacherSource,
     *     preacher_confidence:float|null,
     *     needs_review:bool
     * }
     */
    private function resolvePreacherAssignment(SermonCreationOptions $options): array
    {
        $explicitPreacher = $this->normalizePreacherInput($options->preacher);

        if ($explicitPreacher !== null) {
            $preacherModel = $this->resolveExplicitPreacher($explicitPreacher, $options->preacherId);

            return [
                'preacher_name' => $preacherModel->name,
                'preacher_model' => $preacherModel,
                'preacher_source' => $options->preacherSource ?? PreacherSource::MANUAL,
                'preacher_confidence' => $options->preacherConfidence,
                'needs_review' => $options->needsPreacherReview ?? false,
            ];
        }

        $id3Preacher = $this->normalizePreacherInput($options->id3Preacher);
        $preacherName = $id3Preacher ?? 'Visiting Speaker';
        $preacherSource = $id3Preacher !== null ? PreacherSource::ID3 : PreacherSource::DEFAULT;

        return [
            'preacher_name' => $preacherName,
            'preacher_model' => $this->preacherResolutionService->resolve($preacherName),
            'preacher_source' => $preacherSource,
            'preacher_confidence' => null,
            'needs_review' => $preacherSource === PreacherSource::DEFAULT,
        ];
    }

    private function resolveExplicitPreacher(string $preacherName, ?int $preacherId): Preacher
    {
        if ($preacherId !== null) {
            $preacher = Preacher::query()->find($preacherId);

            if ($preacher instanceof Preacher) {
                return $preacher;
            }
        }

        return $this->preacherResolutionService->resolve($preacherName);
    }

    private function normalizePreacherInput(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $normalized = $this->preacherResolutionService->normalizeWhitespace($name);

        return $normalized === '' ? null : $normalized;
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
        $processingMetadata = $processingLog->processing_metadata?->toArray() ?? [];

        if (isset($processingMetadata['extracted_date'])) {
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
    ): SermonService {
        // Strategy 1: Check if service was extracted from file metadata
        $processingMetadata = $processingLog->processing_metadata?->toArray() ?? [];

        if (isset($processingMetadata['extracted_service'])) {
            $extractedService = $processingMetadata['extracted_service'];
            Log::info('SermonCreationService: Using service extracted from file metadata', [
                'processing_id' => $processingLog->processing_id,
                'extracted_service' => $extractedService,
                'extraction_method' => $processingMetadata['service_extraction_method'] ?? 'unknown',
            ]);

            return SermonService::tryFrom((string) $extractedService) ?? SermonService::MORNING;
        }

        // Strategy 2: Fall back to filename parsing
        $filename = strtolower($filename);

        // Check for evening service indicators (pm or evening)
        if (str_contains($filename, 'evening') || preg_match('/[-_\s]pm\b/i', $filename)) {
            Log::info('SermonCreationService: Detected evening service from filename', [
                'processing_id' => $processingLog->processing_id,
                'filename' => $filename,
            ]);

            return SermonService::EVENING;
        }

        // Check for morning service indicators (am or morning)
        if (str_contains($filename, 'morning') || preg_match('/[-_\s]am\b/i', $filename)) {
            Log::info('SermonCreationService: Detected morning service from filename', [
                'processing_id' => $processingLog->processing_id,
                'filename' => $filename,
            ]);

            return SermonService::MORNING;
        }

        // Strategy 3: Try to detect service type from time in filename
        $hour = $this->extractTimeFromFilename($filename);
        if ($hour !== null) {
            $service = $hour < 12 ? SermonService::MORNING : SermonService::EVENING;
            Log::info('SermonCreationService: Detected service from time in filename', [
                'processing_id' => $processingLog->processing_id,
                'filename' => $filename,
                'extracted_hour' => $hour,
                'service' => $service->value,
            ]);

            return $service;
        }

        // Strategy 4: Default to morning if no service pattern found
        Log::info('SermonCreationService: Defaulting to morning service', [
            'processing_id' => $processingLog->processing_id,
            'filename' => $filename,
        ]);

        return SermonService::MORNING;
    }

    /**
     * Generate sermon title using specified strategy
     *
     * @param  array<string, mixed>  $context
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
     * Generate title using ID3 tags first, then AI analysis, then filename
     *
     * @param  array<string, mixed>  $context
     */
    private function generateTitleAiWithFallback(array $context): string
    {
        // Priority 1: ID3 tag title (if present)
        $id3Title = $context['id3_title'] ?? null;
        if ($id3Title && ! empty(trim($id3Title))) {
            return Str::limit($id3Title, 100, '');
        }

        // Priority 2: AI-generated title (if available)
        $aiAnalysis = $context['ai_analysis'] ?? null;
        if ($aiAnalysis && ! empty($aiAnalysis['title'])) {
            return Str::limit($aiAnalysis['title'], SermonAnalysisValidator::MAX_TITLE_CHARACTERS, '');
        }

        // Priority 3: Fall back to filename processing
        return $this->generateTitleFromFilename($context);
    }

    /**
     * Generate title from filename only
     *
     * @param  array<string, mixed>  $context
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
                $serviceValue = $context['service'] ?? (str_contains(strtolower($filename), 'evening') ? 'evening' : 'morning');
                $service = $serviceValue instanceof SermonService ? $serviceValue : (SermonService::tryFrom((string) $serviceValue) ?? SermonService::MORNING);
            }

            $serviceLabel = $service->label();

            // Use processing log created_at if available, otherwise parse date
            if ($processingLog) {
                $title = $serviceLabel.' Sermon - '.($processingLog->created_at ?? now())->format('F j, Y');
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

    /**
     * Extract time from filename and return hour (0-23) or null if not found
     * Matches formats like: 14:00, 1400, 06-30, 18_30
     * Avoids matching dates like 2025-10-19 or 19-10-2024
     */
    private function extractTimeFromFilename(string $filename): ?int
    {
        // Remove file extension for cleaner matching
        $baseFilename = pathinfo($filename, PATHINFO_FILENAME);

        // Strategy 1: Match time with colon separator anywhere (safest since colons aren't used in dates)
        // Pattern: (?<!\d)(\d{1,2}):(\d{2})(?!\d)
        // Examples: "2024-10-19-18:00", "sermon-14:00", "recording-9:30.mp3"
        if (preg_match('/(?<!\d)(\d{1,2}):(\d{2})(?!\d)/', $baseFilename, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            // Validate it's a real time (hour 0-23, minute 0-59)
            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        // Strategy 2: Match HHMM format (4 consecutive digits) after a complete date
        // Pattern: (?:\d{4}[-_]\d{1,2}[-_]\d{1,2}|\d{1,2}[-_]\d{1,2}[-_]\d{4})[-_](\d{2})(\d{2})
        // Examples: "2024-10-19-1830", "2024-10-19_0930", "19-10-2024-1400"
        // This looks for date pattern followed by separator and then 4 digits
        if (preg_match('/(?:\d{4}[-_]\d{1,2}[-_]\d{1,2}|\d{1,2}[-_]\d{1,2}[-_]\d{4})[-_](\d{2})(\d{2})/', $baseFilename, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            // Validate it's a real time
            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        // Strategy 3: Match time with dash/underscore separator AFTER a date
        // Pattern: (?:\d{4}[-_]\d{1,2}[-_]\d{1,2}|\d{1,2}[-_]\d{1,2}[-_]\d{4})[-_](\d{1,2})[-_](\d{2})
        // Examples: "2024-10-19_18-30", "2024-10-19-14-30"
        if (preg_match('/(?:\d{4}[-_]\d{1,2}[-_]\d{1,2}|\d{1,2}[-_]\d{1,2}[-_]\d{4})[-_](\d{1,2})[-_](\d{2})/', $baseFilename, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            // Validate it's a real time
            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        // Strategy 4: Match standalone HHMM format at start of filename only
        // This is safe because dates don't typically start with just 4 digits
        // Pattern: ^(\d{2})(\d{2})(?![-_]?\d)
        // Examples: "1830-sermon", "0930_recording"
        // Will NOT match: "sermon-1830" (not at start), "19102024" (more than 4 digits)
        if (preg_match('/^(\d{2})(\d{2})(?![-_]?\d)/', $baseFilename, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            // Validate it's a real time
            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return $hour;
            }
        }

        return null;
    }
}
