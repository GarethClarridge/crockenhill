<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Data\SermonCreationOptions;
use App\Enums\MediaType;
use App\Enums\PreacherSource;
use App\Enums\SermonRichnessLevel;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\SermonUpsertAction;
use App\Enums\TitleGenerationStrategy;
use App\Exceptions\SermonRichnessDowngradeException;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Preacher\PreacherResolutionService;
use App\Services\Public\SermonRepository;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SermonCreationService
{
    use SanitizesLogData;

    public function __construct(
        private readonly PreacherResolutionService $preacherResolutionService,
        private readonly SermonRepository $sermonRepository,
        private readonly SermonFilenameParser $filenameParser,
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
     * @throws \Exception For underlying service or repository failures
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

        if (blank($existing)) {
            return $this->createFresh($processingLog, $options, $sermonDate, $service);
        }

        $existingLevel = $this->detectExistingRichness($existing);
        $incomingLevel = $this->detectIncomingRichness($processingLog, $options);
        $action = $this->decideUpsertAction($existingLevel, $incomingLevel);

        return match ($action) {
            SermonUpsertAction::Enrich => $this->enrichExisting($existing, $processingLog, $options),
            SermonUpsertAction::Replace => $this->replaceExisting($existing, $processingLog, $options),
            SermonUpsertAction::Reject => $this->rejectOrForce(
                $existing,
                $processingLog,
                $options,
                $service,
                $existingLevel,
                $incomingLevel,
            ),
        };
    }

    /**
     * @throws SermonRichnessDowngradeException
     */
    private function rejectOrForce(
        Sermon $existing,
        MediaProcessingLog $processingLog,
        SermonCreationOptions $options,
        SermonService $service,
        SermonRichnessLevel $existingLevel,
        SermonRichnessLevel $incomingLevel,
    ): Sermon {
        if ($options->forceOverwrite) {
            Log::warning('SermonCreationService: forceOverwrite bypassed richness downgrade rejection', $this->sanitizeArrayForLog([
                'sermon_id' => $existing->id,
                'processing_id' => $processingLog->processing_id,
                'existing_level' => $existingLevel->name,
                'incoming_level' => $incomingLevel->name,
            ]));

            return $this->replaceExisting($existing, $processingLog, $options);
        }

        Log::warning('SermonCreationService: rejecting richness downgrade', $this->sanitizeArrayForLog([
            'sermon_id' => $existing->id,
            'processing_id' => $processingLog->processing_id,
            'existing_level' => $existingLevel->name,
            'incoming_level' => $incomingLevel->name,
        ]));

        throw SermonRichnessDowngradeException::forExisting(
            $existing,
            $service,
            $this->incomingMediaType($processingLog, $options),
        );
    }

    private function detectExistingRichness(Sermon $existing): SermonRichnessLevel
    {
        if (filled($existing->livestream_processing_id)) {
            return SermonRichnessLevel::Livestream;
        }

        if (filled($existing->video_file_path)) {
            return SermonRichnessLevel::Video;
        }

        return SermonRichnessLevel::Audio;
    }

    private function detectIncomingRichness(MediaProcessingLog $processingLog, SermonCreationOptions $options): SermonRichnessLevel
    {
        return match ($this->incomingMediaType($processingLog, $options)) {
            MediaType::Livestream => SermonRichnessLevel::Livestream,
            MediaType::Video => SermonRichnessLevel::Video,
            MediaType::Audio => SermonRichnessLevel::Audio,
        };
    }

    private function incomingMediaType(MediaProcessingLog $processingLog, SermonCreationOptions $options): MediaType
    {
        return $processingLog->processing_type;
    }

    private function decideUpsertAction(SermonRichnessLevel $existing, SermonRichnessLevel $incoming): SermonUpsertAction
    {
        if ($incoming->value > $existing->value) {
            return SermonUpsertAction::Enrich;
        }

        if ($incoming->value === $existing->value) {
            return SermonUpsertAction::Replace;
        }

        return SermonUpsertAction::Reject;
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

        if (filled($options->videoFilePath)) {
            $updates['video_file_path'] = $options->videoFilePath;
        }

        if (filled($options->livestreamProcessingId)) {
            $updates['livestream_processing_id'] = $options->livestreamProcessingId;
        }

        if (filled($options->segmentStartTime)) {
            $updates['segment_start_time'] = $options->segmentStartTime;
        }

        if (filled($options->segmentEndTime)) {
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

        // A stored duration of 0 (or null) counts as missing, so a richer
        // livestream/video source can still backfill the real length.
        if (($existing->duration === null || $existing->duration <= 0) && filled($options->duration)) {
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

        if (filled($updates)) {
            $existing->fill($updates);
            $existing->save();
        }

        Log::info('SermonCreationService: enriched existing sermon', $this->sanitizeArrayForLog([
            'sermon_id' => $existing->id,
            'processing_id' => $processingLog->processing_id,
            'fields_updated' => array_keys($updates),
        ]));

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

        if (filled($options->transcriptFilePath)) {
            $updates['transcript_file_path'] = $options->transcriptFilePath;
        }

        if ($incoming === MediaType::Livestream) {
            if (filled($options->livestreamProcessingId)) {
                $updates['livestream_processing_id'] = $options->livestreamProcessingId;
            }

            if (filled($options->segmentStartTime)) {
                $updates['segment_start_time'] = $options->segmentStartTime;
            }

            if (filled($options->segmentEndTime)) {
                $updates['segment_end_time'] = $options->segmentEndTime;
            }
        }

        if (filled($options->duration)) {
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

        if (filled($updates)) {
            $existing->fill($updates);
            $existing->save();
        }

        Log::info('SermonCreationService: replaced existing sermon media', $this->sanitizeArrayForLog([
            'sermon_id' => $existing->id,
            'processing_id' => $processingLog->processing_id,
            'fields_updated' => array_keys($updates),
        ]));

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
        if (filled($incoming) && blank($existing->{$field})) {
            $updates[$field] = $incoming;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function fillSeriesIfBlank(Sermon $existing, SermonCreationOptions $options, array &$updates): void
    {
        if (filled($existing->series)) {
            return;
        }

        $value = $options->id3Series ?? ($options->aiAnalysis['series'] ?? null);

        if (filled($value)) {
            $updates['series'] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function fillReferenceIfBlank(Sermon $existing, SermonCreationOptions $options, array &$updates): void
    {
        if (filled($existing->reference)) {
            return;
        }

        $value = $options->id3Reference ?? ($options->aiAnalysis['reference'] ?? null);

        if (filled($value)) {
            $updates['reference'] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function fillPointsIfBlank(Sermon $existing, SermonCreationOptions $options, array &$updates): void
    {
        if (filled($existing->points)) {
            return;
        }

        if (filled($options->aiAnalysis['points'] ?? null)) {
            $updates['points'] = $options->aiAnalysis['points'];
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function refreshAiField(Sermon $existing, array &$updates, string $field, mixed $value): void
    {
        if (blank($value)) {
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

        if (filled($explicitPreacher)) {
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
        $preacherSource = filled($id3Preacher) ? PreacherSource::Id3 : PreacherSource::Default;

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
        if (filled($preacherId)) {
            $preacher = Preacher::query()->find($preacherId);

            if ($preacher instanceof Preacher) {
                return $preacher;
            }
        }

        return $this->preacherResolutionService->resolve($preacherName);
    }

    private function normalizePreacherInput(?string $name): ?string
    {
        if (blank($name)) {
            return null;
        }

        $normalized = $this->preacherResolutionService->normalizeWhitespace($name);

        return filled($normalized) ? $normalized : null;
    }

    /**
     * Extract sermon date using cascading strategy.
     */
    public function extractDate(
        MediaProcessingLog $processingLog,
        string $filename
    ): string {
        if (filled($processingLog->extracted_date)) {
            $extractedDate = $processingLog->extracted_date->toDateString();
            $processingMetadata = $processingLog->processing_metadata?->toArray() ?? [];

            Log::info('SermonCreationService: Using date extracted from file metadata', $this->sanitizeArrayForLog([
                'processing_id' => $processingLog->processing_id,
                'extracted_date' => $extractedDate,
                'extraction_method' => (string) ($processingMetadata['date_extraction_method'] ?? 'unknown'),
            ]));

            return $extractedDate;
        }

        $filenameDate = $this->filenameParser->extractDateFromFilename($filename)->toDateString();

        Log::info('SermonCreationService: Using date extracted from filename', $this->sanitizeArrayForLog([
            'processing_id' => $processingLog->processing_id,
            'filename' => $filename,
            'extracted_date' => $filenameDate,
        ]));

        return $filenameDate;
    }

    /**
     * Extract service type using cascading strategy.
     */
    public function extractServiceType(
        MediaProcessingLog $processingLog,
        string $filename
    ): SermonService {
        if ($processingLog->extracted_service instanceof SermonService) {
            $processingMetadata = $processingLog->processing_metadata?->toArray() ?? [];

            Log::info('SermonCreationService: Using service extracted from file metadata', $this->sanitizeArrayForLog([
                'processing_id' => $processingLog->processing_id,
                'extracted_service' => $processingLog->extracted_service->value,
                'extraction_method' => (string) ($processingMetadata['service_extraction_method'] ?? 'unknown'),
            ]));

            return $processingLog->extracted_service;
        }

        $service = $this->filenameParser->determineServiceFromFilename($filename);

        Log::info('SermonCreationService: Using service detected from filename', $this->sanitizeArrayForLog([
            'processing_id' => $processingLog->processing_id,
            'filename' => $filename,
            'service' => $service->value,
        ]));

        return $service;
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
        if (filled($id3Title)) {
            return Str::limit($id3Title, 100, '');
        }

        // Priority 2: AI-generated title (if available)
        $aiAnalysis = $context['ai_analysis'] ?? null;
        if (filled($aiAnalysis['title'] ?? null)) {
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

        if (blank($filename)) {
            return 'Sermon - '.now()->format('F j, Y');
        }

        $baseFilename = pathinfo($filename, PATHINFO_FILENAME);

        $title = $this->cleanFilenameForTitle($baseFilename);

        // If title is empty or too short, use a default
        if (blank($title) || strlen($title) < 3 || $this->looksLikeFilenameFragment($title)) {
            // Try to build from context
            $date = $context['date'] ?? $this->filenameParser->extractDateFromFilename($filename)->toDateString();

            // Extract service type - only if processing log is available
            if ($processingLog) {
                $service = $context['service'] ?? $this->extractServiceType($processingLog, $filename);
            } else {
                // Fallback: simple filename parsing when no processing log
                $service = $context['service'] ?? $this->filenameParser->determineServiceFromFilename($filename);
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
     * Clean a filename for use as a sermon title.
     *
     * Removes date patterns, common sermon keywords, and replaces separators with spaces.
     */
    private function cleanFilenameForTitle(string $filename): string
    {
        // Remove common date patterns (YYYY-MM-DD, DD-MM-YYYY)
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
        $title = preg_replace('/\d{1,2}[-_]\d{1,2}[-_]\d{4}/', '', $title ?? '');

        // Remove common sermon-related words and clean up
        $title = preg_replace('/\b(sermon|message|service|am|pm)\b/i', '', $title ?? '');
        $title = preg_replace('/[-_]+/', ' ', $title ?? '');

        return trim($title ?? '');
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

        if (blank($normalized)) {
            return true;
        }

        // Match patterns like "10 24" or "10 24 2024" (mostly numeric fragments)
        if (preg_match('/^\d{1,2}(?:\s+\d{2})+(?:\s+\d+)?$/', $normalized) === 1) {
            return true;
        }

        // Match strings composed only of digits, whitespace, and separators (-, _, :)
        return preg_match('/^[\d\s:_-]+$/', $normalized) === 1;
    }
}
