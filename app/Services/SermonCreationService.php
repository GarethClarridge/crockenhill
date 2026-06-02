<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\SermonCreationOptions;
use App\Enums\MediaType;
use App\Enums\PreacherSource;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\TitleGenerationStrategy;
use App\Exceptions\SermonRichnessDowngradeException;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SermonCreationService
{
    use SanitizesLogData;

    public function __construct(
        private readonly PreacherResolutionService $preacherResolutionService,
        private readonly SermonRepository $sermonRepository,
    ) {}

    /**
     * Create or update a sermon record based on a "richness-aware" upsert strategy.
     *
     * This method manages the lifecycle of sermon records when new media is processed.
     * It uses a richness hierarchy (Livestream > Video > Audio) to decide whether to:
     * - Enrich: Incoming pipeline is richer than the existing record (e.g., adding video to an audio-only sermon).
     * - Replace: Incoming and existing have same richness (e.g., re-uploading audio).
     * - Reject: Refuse to downgrade (e.g., uploading audio for a sermon that already has video).
     *
     * @param  MediaProcessingLog  $processingLog  The log of the current processing run
     * @param  SermonCreationOptions  $options  Consolidated options and metadata for creation
     * @return Sermon The created or updated sermon model
     *
     * @throws SermonRichnessDowngradeException When attempting to overwrite a richer record
     */
    public function createSermon(
        MediaProcessingLog $processingLog,
        SermonCreationOptions $options
    ): Sermon {
        $sermonDate = $options->date ?? $this->extractDate($processingLog, $options->originalFilename);
        $service = $options->service ?? $this->extractServiceType($processingLog, $options->originalFilename);

        $existing = $this->sermonRepository->findByDateAndServiceAndContentType(
            $sermonDate,
            $service,
            $options->contentType,
        );

        if ($existing === null) {
            return $this->createFresh($processingLog, $options, $sermonDate, $service);
        }

        $existingLevel = $this->detectExistingRichness($existing);
        $incomingLevel = $this->detectIncomingRichness($processingLog, $options);
        $action = $this->decideUpsertAction($existingLevel, $incomingLevel);

        return match ($action) {
            UpsertAction::Enrich => $this->enrichExisting($existing, $processingLog, $options),
            UpsertAction::Replace => $this->replaceExisting($existing, $processingLog, $options),
            UpsertAction::Reject => $this->rejectOrForce(
                $existing,
                $processingLog,
                $options,
                $service,
                $existingLevel,
                $incomingLevel,
            ),
        };
    }

    private function rejectOrForce(
        Sermon $existing,
        MediaProcessingLog $processingLog,
        SermonCreationOptions $options,
        SermonService $service,
        RichnessLevel $existingLevel,
        RichnessLevel $incomingLevel,
    ): Sermon {
        if ($options->forceOverwrite) {
            Log::warning('SermonCreationService: forceOverwrite bypassed richness downgrade rejection', [
                'sermon_id' => $existing->id,
                'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
                'existing_level' => $existingLevel->name,
                'incoming_level' => $incomingLevel->name,
            ]);

            return $this->replaceExisting($existing, $processingLog, $options);
        }

        Log::warning('SermonCreationService: rejecting richness downgrade', [
            'sermon_id' => $existing->id,
            'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
            'existing_level' => $existingLevel->name,
            'incoming_level' => $incomingLevel->name,
        ]);

        throw SermonRichnessDowngradeException::forExisting(
            $existing,
            $service,
            $this->incomingMediaType($processingLog, $options),
        );
    }

    private function detectExistingRichness(Sermon $existing): RichnessLevel
    {
        if ($existing->livestream_processing_id !== null) {
            return RichnessLevel::Livestream;
        }

        if (! empty($existing->video_file_path)) {
            return RichnessLevel::Video;
        }

        return RichnessLevel::Audio;
    }

    private function detectIncomingRichness(MediaProcessingLog $processingLog, SermonCreationOptions $options): RichnessLevel
    {
        return match ($this->incomingMediaType($processingLog, $options)) {
            MediaType::Livestream => RichnessLevel::Livestream,
            MediaType::Video => RichnessLevel::Video,
            MediaType::Audio => RichnessLevel::Audio,
        };
    }

    private function incomingMediaType(MediaProcessingLog $processingLog, SermonCreationOptions $options): MediaType
    {
        return $processingLog->processing_type;
    }

    private function decideUpsertAction(RichnessLevel $existing, RichnessLevel $incoming): UpsertAction
    {
        if ($incoming->value > $existing->value) {
            return UpsertAction::Enrich;
        }

        if ($incoming->value === $existing->value) {
            return UpsertAction::Replace;
        }

        return UpsertAction::Reject;
    }

    /**
     * Strictly additive upgrade: incoming pipeline is richer than the existing record.
     * Manual edits and identity-shaping fields (slug/title/date/service/notes) are preserved.
     */
    private function enrichExisting(
        Sermon $existing,
        MediaProcessingLog $processingLog,
        SermonCreationOptions $options,
    ): Sermon {
        $updates = [];

        if ($options->videoFilePath) {
            $updates['video_file_path'] = $options->videoFilePath;
        }

        if ($options->livestreamProcessingId) {
            $updates['livestream_processing_id'] = $options->livestreamProcessingId;
        }

        if ($options->segmentStartTime !== null) {
            $updates['segment_start_time'] = $options->segmentStartTime;
        }

        if ($options->segmentEndTime !== null) {
            $updates['segment_end_time'] = $options->segmentEndTime;
        }

        // Upgrade source_type if incoming is richer.
        if ($this->shouldUpgradeSourceType($existing, $options)) {
            $updates['source_type'] = $options->sourceType;
        }

        // Set-if-null fields: AI-derived metadata fills in gaps without overwriting.
        $this->fillIfBlank($existing, $updates, 'transcript_file_path', $options->transcriptFilePath);
        $this->fillSeriesIfBlank($existing, $options, $updates);
        $this->fillReferenceIfBlank($existing, $options, $updates);
        $this->fillPointsIfBlank($existing, $options, $updates);

        if (empty($existing->duration) && $options->duration !== null) {
            $updates['duration'] = $options->duration;
        }

        // Preacher: only replace the placeholder "Visiting Speaker" default.
        if ($existing->preacher_source === PreacherSource::Default) {
            $resolved = $this->resolvePreacherAssignment($options);

            if ($resolved['preacher_source'] !== PreacherSource::Default) {
                $updates['preacher'] = $resolved['preacher_model']->name;
                $updates['preacher_id'] = $resolved['preacher_model']->id;
                $updates['preacher_source'] = $resolved['preacher_source'];
                $updates['preacher_confidence'] = $resolved['preacher_confidence'];
                $updates['needs_preacher_review'] = $resolved['needs_review'];
            }
        }

        if ($updates !== []) {
            $existing->fill($updates);
            $existing->save();
        }

        Log::info('SermonCreationService: enriched existing sermon', [
            'sermon_id' => $existing->id,
            'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
            'fields_updated' => array_map(fn (string $key) => $this->sanitizeForLog($key), array_keys($updates)),
        ]);

        return $existing->refresh();
    }

    /**
     * Same richness, refresh mutable media + AI-derived fields. Preserves identity and manual edits.
     */
    private function replaceExisting(
        Sermon $existing,
        MediaProcessingLog $processingLog,
        SermonCreationOptions $options,
    ): Sermon {
        $updates = [];

        $incoming = $this->incomingMediaType($processingLog, $options);

        if ($incoming === MediaType::Audio || $incoming === MediaType::Livestream) {
            $updates['audio_file_path'] = $options->audioFilePath;
        }

        if (($incoming === MediaType::Video || $incoming === MediaType::Livestream) && $options->videoFilePath) {
            $updates['video_file_path'] = $options->videoFilePath;
        }

        if ($options->transcriptFilePath) {
            $updates['transcript_file_path'] = $options->transcriptFilePath;
        }

        if ($incoming === MediaType::Livestream) {
            if ($options->livestreamProcessingId) {
                $updates['livestream_processing_id'] = $options->livestreamProcessingId;
            }

            if ($options->segmentStartTime !== null) {
                $updates['segment_start_time'] = $options->segmentStartTime;
            }

            if ($options->segmentEndTime !== null) {
                $updates['segment_end_time'] = $options->segmentEndTime;
            }
        }

        if ($options->duration !== null) {
            $updates['duration'] = $options->duration;
        }

        // AI-derived fields: refresh when not Manual.
        $this->refreshAiField($existing, $updates, 'series', $options->id3Series ?? ($options->aiAnalysis['series'] ?? null));
        $this->refreshAiField($existing, $updates, 'reference', $options->id3Reference ?? ($options->aiAnalysis['reference'] ?? null));

        if (isset($options->aiAnalysis['points'])) {
            $updates['points'] = $options->aiAnalysis['points'];
        }

        // Preacher: only refresh when not Manual.
        if ($existing->preacher_source !== PreacherSource::Manual) {
            $resolved = $this->resolvePreacherAssignment($options);
            $updates['preacher'] = $resolved['preacher_model']->name;
            $updates['preacher_id'] = $resolved['preacher_model']->id;
            $updates['preacher_source'] = $resolved['preacher_source'];
            $updates['preacher_confidence'] = $resolved['preacher_confidence'];
            $updates['needs_preacher_review'] = $resolved['needs_review'];
        }

        if ($updates !== []) {
            $existing->fill($updates);
            $existing->save();
        }

        Log::info('SermonCreationService: replaced existing sermon media', [
            'sermon_id' => $existing->id,
            'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
            'fields_updated' => array_map(fn (string $key) => $this->sanitizeForLog($key), array_keys($updates)),
        ]);

        return $existing->refresh();
    }

    private function shouldUpgradeSourceType(Sermon $existing, SermonCreationOptions $options): bool
    {
        $rank = static fn (?SermonSourceType $type): int => match ($type) {
            SermonSourceType::Livestream => 3,
            SermonSourceType::VideoUpload => 2,
            SermonSourceType::AudioUpload, SermonSourceType::Manual => 1,
            null => 0,
        };

        return $rank($options->sourceType) > $rank($existing->source_type);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function fillIfBlank(Sermon $existing, array &$updates, string $field, ?string $incoming): void
    {
        if ($incoming !== null && $incoming !== '' && empty($existing->{$field})) {
            $updates[$field] = $incoming;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function fillSeriesIfBlank(Sermon $existing, SermonCreationOptions $options, array &$updates): void
    {
        if (! empty($existing->series)) {
            return;
        }

        $value = $options->id3Series ?? ($options->aiAnalysis['series'] ?? null);

        if (is_string($value) && $value !== '') {
            $updates['series'] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function fillReferenceIfBlank(Sermon $existing, SermonCreationOptions $options, array &$updates): void
    {
        if (! empty($existing->reference)) {
            return;
        }

        $value = $options->id3Reference ?? ($options->aiAnalysis['reference'] ?? null);

        if (is_string($value) && $value !== '') {
            $updates['reference'] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function fillPointsIfBlank(Sermon $existing, SermonCreationOptions $options, array &$updates): void
    {
        if (! empty($existing->points)) {
            return;
        }

        if (isset($options->aiAnalysis['points'])) {
            $updates['points'] = $options->aiAnalysis['points'];
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function refreshAiField(Sermon $existing, array &$updates, string $field, mixed $value): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $updates[$field] = $value;
    }

    private function createFresh(
        MediaProcessingLog $processingLog,
        SermonCreationOptions $options,
        string $sermonDate,
        SermonService $service,
    ): Sermon {
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

        $slug = $this->sermonRepository->generateUniqueSlug($title);

        [
            'preacher_name' => $preacherName,
            'preacher_model' => $preacherModel,
            'preacher_source' => $preacherSource,
            'preacher_confidence' => $preacherConfidence,
            'needs_review' => $needsReview,
        ] = $this->resolvePreacherAssignment($options);

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

        if ($options->videoFilePath) {
            $sermonData['video_file_path'] = $options->videoFilePath;
        }

        if ($options->transcriptFilePath) {
            $sermonData['transcript_file_path'] = $options->transcriptFilePath;
        }

        if ($options->livestreamProcessingId) {
            $sermonData['livestream_processing_id'] = $options->livestreamProcessingId;
        }

        if ($options->id3Series) {
            $sermonData['series'] = $options->id3Series;
        } elseif ($options->aiAnalysis && isset($options->aiAnalysis['series'])) {
            $sermonData['series'] = $options->aiAnalysis['series'];
        }

        if ($options->id3Reference) {
            $sermonData['reference'] = $options->id3Reference;
        } elseif ($options->aiAnalysis && isset($options->aiAnalysis['reference'])) {
            $sermonData['reference'] = $options->aiAnalysis['reference'];
        }

        if ($options->aiAnalysis && array_key_exists('points', $options->aiAnalysis)) {
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
                'preacher_source' => $options->preacherSource ?? PreacherSource::Manual,
                'preacher_confidence' => $options->preacherConfidence,
                'needs_review' => $options->needsPreacherReview ?? false,
            ];
        }

        $id3Preacher = $this->normalizePreacherInput($options->id3Preacher);
        $preacherName = $id3Preacher ?? 'Visiting Speaker';
        $preacherSource = $id3Preacher !== null ? PreacherSource::Id3 : PreacherSource::Default;

        return [
            'preacher_name' => $preacherName,
            'preacher_model' => $this->preacherResolutionService->resolve($preacherName),
            'preacher_source' => $preacherSource,
            'preacher_confidence' => null,
            'needs_review' => $preacherSource === PreacherSource::Default,
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
     * 1. extracted_date column (populated at initiation from video/audio metadata)
     * 2. Filename parsing
     * 3. Current date
     */
    public function extractDate(
        MediaProcessingLog $processingLog,
        string $filename
    ): string {
        if ($processingLog->extracted_date !== null) {
            $extractedDate = $processingLog->extracted_date->toDateString();
            $processingMetadata = $processingLog->processing_metadata?->toArray() ?? [];

            Log::info('SermonCreationService: Using date extracted from file metadata', [
                'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
                'extracted_date' => $this->sanitizeForLog($extractedDate),
                'extraction_method' => $this->sanitizeForLog((string) ($processingMetadata['date_extraction_method'] ?? 'unknown')),
            ]);

            return $extractedDate;
        }

        $filenameDate = $this->extractDateFromFilename($filename);

        Log::info('SermonCreationService: Using date extracted from filename', [
            'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
            'filename' => $this->sanitizeForLog($filename),
            'extracted_date' => $this->sanitizeForLog($filenameDate),
        ]);

        return $filenameDate;
    }

    /**
     * Extract service type using cascading strategy
     * 1. extracted_service column (populated at initiation from file timestamp/filename)
     * 2. Filename parsing
     * 3. Default to morning
     */
    public function extractServiceType(
        MediaProcessingLog $processingLog,
        string $filename
    ): SermonService {
        if ($processingLog->extracted_service instanceof SermonService) {
            $processingMetadata = $processingLog->processing_metadata?->toArray() ?? [];

            Log::info('SermonCreationService: Using service extracted from file metadata', [
                'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
                'extracted_service' => $processingLog->extracted_service->value,
                'extraction_method' => $this->sanitizeForLog((string) ($processingMetadata['service_extraction_method'] ?? 'unknown')),
            ]);

            return $processingLog->extracted_service;
        }

        // Strategy 2: Fall back to filename parsing
        $filename = strtolower($filename);

        // Check for evening service indicators (pm or evening)
        if (str_contains($filename, 'evening') || preg_match('/[-_\s]pm\b/i', $filename)) {
            Log::info('SermonCreationService: Detected evening service from filename', [
                'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
                'filename' => $this->sanitizeForLog($filename),
            ]);

            return SermonService::Evening;
        }

        // Check for morning service indicators (am or morning)
        if (str_contains($filename, 'morning') || preg_match('/[-_\s]am\b/i', $filename)) {
            Log::info('SermonCreationService: Detected morning service from filename', [
                'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
                'filename' => $this->sanitizeForLog($filename),
            ]);

            return SermonService::Morning;
        }

        // Strategy 3: Try to detect service type from time in filename
        $hour = $this->extractTimeFromFilename($filename);
        if ($hour !== null) {
            $service = $hour < 12 ? SermonService::Morning : SermonService::Evening;
            Log::info('SermonCreationService: Detected service from time in filename', [
                'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
                'filename' => $this->sanitizeForLog($filename),
                'extracted_hour' => $hour,
                'service' => $service->value,
            ]);

            return $service;
        }

        // Strategy 4: Default to morning if no service pattern found
        Log::info('SermonCreationService: Defaulting to morning service', [
            'processing_id' => $this->sanitizeForLog($processingLog->processing_id),
            'filename' => $this->sanitizeForLog($filename),
        ]);

        return SermonService::Morning;
    }

    /**
     * Generate sermon title using specified strategy.
     *
     * @param  TitleGenerationStrategy  $strategy  The strategy to use (AI, Filename, Custom)
     * @param  array{
     *     ai_analysis?: array{title: string, series: string|null, reference: string|null, points: list<string>, summary: string|null, transcript: string}|null,
     *     filename: string,
     *     custom_title?: string|null,
     *     id3_title?: string|null,
     *     processing_log?: MediaProcessingLog|null,
     *     date?: string,
     *     service?: SermonService
     * }  $context  Data context for title generation
     * @return string The generated and truncated title
     */
    public function generateTitle(
        TitleGenerationStrategy $strategy,
        array $context
    ): string {
        return match ($strategy) {
            TitleGenerationStrategy::AiWithFallback => $this->generateTitleAiWithFallback($context),
            TitleGenerationStrategy::FilenameOnly => $this->generateTitleFromFilename($context),
            TitleGenerationStrategy::Custom => $context['custom_title'] ?? $this->generateTitleFromFilename($context),
        };
    }

    /**
     * Generate title using ID3 tags first, then AI analysis, then filename.
     *
     * @param  array{
     *     ai_analysis?: array{title: string, series: string|null, reference: string|null, points: list<string>, summary: string|null, transcript: string}|null,
     *     filename: string,
     *     id3_title?: string|null
     * }  $context
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
            return $aiAnalysis['title'];
        }

        // Priority 3: Fall back to filename processing
        return $this->generateTitleFromFilename($context);
    }

    /**
     * Generate title from filename only.
     *
     * @param  array{
     *     filename: string,
     *     processing_log?: MediaProcessingLog|null,
     *     date?: string,
     *     service?: SermonService
     * }  $context
     */
    private function generateTitleFromFilename(array $context): string
    {
        $filename = $context['filename'];
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
        if (empty($title) || strlen($title) < 3 || $this->looksLikeFilenameFragment($title)) {
            // Try to build from context
            $date = $context['date'] ?? $this->extractDateFromFilename($filename);

            // Extract service type - only if processing log is available
            if ($processingLog) {
                $service = $context['service'] ?? $this->extractServiceType($processingLog, $filename);
            } else {
                // Fallback: simple filename parsing when no processing log
                $service = $context['service'] ?? (str_contains(strtolower($filename), 'evening') ? SermonService::Evening : SermonService::Morning);
            }

            $serviceLabel = $service->label();
            $timestamp = strtotime($date) ?: null;
            $title = $serviceLabel.' Sermon - '.date('F j, Y', $timestamp);
        }

        // Capitalize words properly
        $title = Str::title($title);

        // Ensure it's not too long
        return Str::limit($title, 100, '');
    }

    /**
     * Determine if a string looks like an unparsed filename fragment.
     *
     * Returns true for strings that are entirely numbers, spaces, or separators,
     * preventing them from being used as actual sermon titles.
     */
    private function looksLikeFilenameFragment(string $title): bool
    {
        $normalized = trim($title);

        if ($normalized === '') {
            return true;
        }

        // Match patterns like "10 24" or "10 24 2024" (mostly numeric fragments)
        if (preg_match('/^\d{1,2}(?:\s+\d{2})+(?:\s+\d+)?$/', $normalized) === 1) {
            return true;
        }

        // Match strings composed only of digits, whitespace, and separators (-, _, :)
        return preg_match('/^[\d\s:_-]+$/', $normalized) === 1;
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

/**
 * @internal Ranks for the SermonCreationService upsert matrix. Higher value = richer.
 */
enum RichnessLevel: int
{
    case Audio = 1;
    case Video = 2;
    case Livestream = 3;
}

/**
 * @internal The three outcomes for SermonCreationService when an existing record matches.
 */
enum UpsertAction
{
    case Enrich;
    case Replace;
    case Reject;
}
