<?php

namespace App\Data;

use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\ServiceSectionType;
use App\Enums\TitleGenerationStrategy;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;

class SermonCreationOptions
{
    /**
     * @param  array<string, mixed>|null  $aiAnalysis
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
        public TitleGenerationStrategy $titleStrategy = TitleGenerationStrategy::AI_WITH_FALLBACK,

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
    ) {}

    /**
     * Create options for audio upload processing
     *
     * @param  array<string, mixed>  $aiAnalysis
     */
    public static function fromAudioUpload(MediaProcessingLog $log, array $aiAnalysis): self
    {
        return new self(
            audioFilePath: self::requireAudioFilePath($log->source_file_path, $log->processing_id),
            originalFilename: $log->original_filename,
            sourceType: SermonSourceType::AudioUpload,
            transcriptFilePath: $log->transcript_file_path,
            aiAnalysis: $aiAnalysis,
            titleStrategy: TitleGenerationStrategy::AI_WITH_FALLBACK,
            duration: $log->duration,
        );
    }

    /**
     * Create options for video upload processing
     *
     * @param  array<string, mixed>  $aiAnalysis
     */
    public static function fromVideoUpload(MediaProcessingLog $log, array $aiAnalysis): self
    {
        return new self(
            audioFilePath: self::requireAudioFilePath($log->audio_file_path, $log->processing_id),
            originalFilename: $log->original_filename,
            sourceType: SermonSourceType::VideoUpload,
            videoFilePath: $log->video_file_path,
            transcriptFilePath: $log->transcript_file_path,
            aiAnalysis: $aiAnalysis,
            titleStrategy: TitleGenerationStrategy::AI_WITH_FALLBACK,
            duration: $log->duration,
        );
    }

    /**
     * Create options for livestream processing
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function fromLivestream(MediaProcessingLog $log, array $metadata): self
    {
        return new self(
            audioFilePath: self::requireAudioFilePath($log->audio_file_path, $log->processing_id),
            originalFilename: $metadata['original_filename'] ?? $log->original_filename,
            sourceType: SermonSourceType::Livestream,
            videoFilePath: $metadata['video_file_path'] ?? null,
            livestreamProcessingId: $metadata['livestream_processing_id'] ?? $log->processing_id,
            segmentStartTime: $metadata['segment_start_time'] ?? null,
            segmentEndTime: $metadata['segment_end_time'] ?? null,
            titleStrategy: TitleGenerationStrategy::FILENAME_ONLY,
        );
    }

    public static function fromServiceSection(
        ServiceSection $section,
        MediaProcessingLog $log,
        string $date,
        SermonService $service
    ): self {
        $speaker = $section->publicationChildrensTalkSpeaker();

        return new self(
            audioFilePath: self::requireAudioFilePath($section->extracted_audio_path, $log->processing_id),
            originalFilename: $section->title ?: $log->original_filename,
            sourceType: SermonSourceType::Livestream,
            videoFilePath: $section->extracted_video_path,
            livestreamProcessingId: $log->processing_id,
            segmentStartTime: $section->start_time,
            segmentEndTime: $section->end_time,
            contentType: $section->section_type === ServiceSectionType::CHILDRENS_TALK
                ? SermonContentType::ChildrensTalk
                : SermonContentType::Sermon,
            titleStrategy: TitleGenerationStrategy::FILENAME_ONLY,
            preacher: $speaker['preacher_name'] ?? null,
            preacherId: $speaker['preacher_id'] ?? null,
            preacherSource: isset($speaker['source']) ? PreacherSource::tryFrom((string) $speaker['source']) : null,
            preacherConfidence: $speaker['confidence'] ?? null,
            needsPreacherReview: false,
            service: $service,
            date: $date,
            customTitle: $section->title,
            duration: (float) $section->duration,
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
