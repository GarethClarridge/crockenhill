<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\ServiceSectionType;
use App\Enums\TitleGenerationStrategy;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;

final readonly class SermonCreationOptions
{
    /**
     * @param  array{
     *     title: string,
     *     series: string|null,
     *     reference: string|null,
     *     points: list<string>,
     *     summary: string|null,
     *     transcript: string
     * }|null  $aiAnalysis
     */
    public function __construct(
        // Required fields
        public string $audioFilePath,
        public string $originalFilename,
        public SermonSourceType $sourceType,

        // Optional fields with defaults
        public ?string $videoFilePath = null,
        public ?string $transcriptFilePath = null,
        public ?array $aiAnalysis = null,
        public ?string $livestreamProcessingId = null,
        public ?float $segmentStartTime = null,
        public ?float $segmentEndTime = null,
        public SermonContentType $contentType = SermonContentType::Sermon,

        // Title generation strategy
        public TitleGenerationStrategy $titleStrategy = TitleGenerationStrategy::AiWithFallback,

        // Override defaults
        public ?string $preacher = null,
        public ?int $preacherId = null,
        public ?PreacherSource $preacherSource = null,
        public ?float $preacherConfidence = null,
        public ?bool $needsPreacherReview = null,
        public ?SermonService $service = null,
        public ?string $date = null,
        public ?string $customTitle = null,

        // ID3 metadata fields (extracted from audio file tags)
        public ?string $id3Title = null,
        public ?string $id3Preacher = null,
        public ?string $id3Series = null,
        public ?string $id3Reference = null,
        public ?float $duration = null,

        // Bypass the upsert reject path (used by the historic-video import --force flag)
        public bool $forceOverwrite = false,

        // Curated historic manifest facts; outrank ID3 and AI where present (F44)
        public ?HistoricEditorialFacts $editorialFacts = null,
    ) {}

    /**
     * Curated facts describe the service's sermon, so they must not be applied
     * to a children's talk extracted from the same recording.
     */
    public function curatedFacts(): ?HistoricEditorialFacts
    {
        if ($this->contentType !== SermonContentType::Sermon) {
            return null;
        }

        return $this->editorialFacts;
    }

    /**
     * Create options for audio upload processing
     *
     * @param  array{
     *     title: string,
     *     series: string|null,
     *     reference: string|null,
     *     points: list<string>,
     *     summary: string|null,
     *     transcript: string
     * }|null  $aiAnalysis
     */
    public static function fromAudioUpload(MediaProcessingLog $log, ?array $aiAnalysis): self
    {
        $id3 = $log->processing_metadata?->id3Metadata;

        return new self(
            audioFilePath: self::requireAudioFilePath($log->source_file_path, $log->processing_id),
            originalFilename: $log->original_filename,
            sourceType: SermonSourceType::AudioUpload,
            transcriptFilePath: $log->transcript_file_path,
            aiAnalysis: $aiAnalysis,
            titleStrategy: TitleGenerationStrategy::AiWithFallback,
            service: $log->extracted_service,
            date: $log->extracted_date?->toDateString(),
            id3Title: $id3?->title,
            id3Preacher: $id3?->preacher,
            id3Series: $id3?->series,
            id3Reference: $id3?->reference,
            duration: $log->duration,
            editorialFacts: $log->processing_metadata?->editorialFacts,
        );
    }

    /**
     * Create options for video upload processing
     *
     * @param  array{
     *     title: string,
     *     series: string|null,
     *     reference: string|null,
     *     points: list<string>,
     *     summary: string|null,
     *     transcript: string
     * }|null  $aiAnalysis
     */
    public static function fromVideoUpload(MediaProcessingLog $log, ?array $aiAnalysis): self
    {
        $id3 = $log->processing_metadata?->id3Metadata;

        return new self(
            audioFilePath: self::requireAudioFilePath($log->audio_file_path, $log->processing_id),
            originalFilename: $log->original_filename,
            sourceType: SermonSourceType::VideoUpload,
            videoFilePath: $log->video_file_path,
            transcriptFilePath: $log->transcript_file_path,
            aiAnalysis: $aiAnalysis,
            titleStrategy: TitleGenerationStrategy::AiWithFallback,
            service: $log->extracted_service,
            date: $log->extracted_date?->toDateString(),
            id3Title: $id3?->title,
            id3Preacher: $id3?->preacher,
            id3Series: $id3?->series,
            id3Reference: $id3?->reference,
            duration: $log->duration,
            editorialFacts: $log->processing_metadata?->editorialFacts,
        );
    }

    /**
     * Create options for livestream processing
     *
     * @param  array{
     *     original_filename?: string,
     *     video_file_path?: string|null,
     *     livestream_processing_id?: string,
     *     segment_start_time?: float|null,
     *     segment_end_time?: float|null,
     * }  $metadata
     */
    public static function fromLivestream(MediaProcessingLog $log, array $metadata): self
    {
        $facts = $log->processing_metadata?->editorialFacts;

        return new self(
            audioFilePath: self::requireAudioFilePath($log->audio_file_path, $log->processing_id),
            originalFilename: $metadata['original_filename'] ?? $log->original_filename,
            sourceType: SermonSourceType::Livestream,
            videoFilePath: $metadata['video_file_path'] ?? null,
            livestreamProcessingId: $metadata['livestream_processing_id'] ?? $log->processing_id,
            segmentStartTime: $metadata['segment_start_time'] ?? null,
            segmentEndTime: $metadata['segment_end_time'] ?? null,
            titleStrategy: TitleGenerationStrategy::FilenameOnly,
            preacher: $facts?->speaker,
            preacherSource: $facts?->speaker === null ? null : PreacherSource::Manual,
            needsPreacherReview: $facts?->speaker === null ? null : false,
            service: $log->extracted_service,
            date: $log->extracted_date?->toDateString(),
            editorialFacts: $facts,
        );
    }

    public static function fromServiceSection(
        ServiceSection $section,
        MediaProcessingLog $log,
        string $date,
        SermonService $service
    ): self {
        $speaker = $section->publicationChildrensTalkSpeaker();
        $contentType = $section->section_type === ServiceSectionType::ChildrensTalk
            ? SermonContentType::ChildrensTalk
            : SermonContentType::Sermon;

        $facts = $contentType === SermonContentType::Sermon
            ? $log->processing_metadata?->editorialFacts
            : null;

        $curatedSpeaker = $speaker === null ? $facts?->speaker : null;

        return new self(
            audioFilePath: self::requireAudioFilePath($section->extracted_audio_path, $log->processing_id),
            originalFilename: $section->title ?: $log->original_filename,
            sourceType: SermonSourceType::Livestream,
            videoFilePath: $section->extracted_video_path,
            livestreamProcessingId: $log->processing_id,
            segmentStartTime: $section->start_time,
            segmentEndTime: $section->end_time,
            contentType: $contentType,
            titleStrategy: TitleGenerationStrategy::FilenameOnly,
            preacher: $speaker['preacher_name'] ?? $curatedSpeaker,
            preacherId: $speaker['preacher_id'] ?? null,
            preacherSource: isset($speaker['source'])
                ? PreacherSource::tryFrom((string) $speaker['source'])
                : ($curatedSpeaker === null ? null : PreacherSource::Manual),
            preacherConfidence: $speaker['confidence'] ?? null,
            needsPreacherReview: false,
            service: $service,
            date: $date,
            customTitle: $section->title,
            duration: (float) $section->duration,
            editorialFacts: $facts,
        );
    }

    private static function requireAudioFilePath(?string $audioFilePath, string $processingId): string
    {
        if (! is_string($audioFilePath) || $audioFilePath === '') {
            throw new \InvalidArgumentException("Missing audio file path for processing log {$processingId}");
        }

        return $audioFilePath;
    }
}
