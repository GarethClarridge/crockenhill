<?php

namespace App\Data;

use App\Enums\TitleGenerationStrategy;
use App\Models\MediaProcessingLog;

class SermonCreationOptions
{
    public function __construct(
        // Required fields
        public string $audioFilePath,
        public string $originalFilename,
        public string $sourceType,

        // Optional fields with defaults
        public ?string $videoFilePath = null,
        public ?string $transcriptFilePath = null,
        public ?array $aiAnalysis = null,
        public ?string $livestreamProcessingId = null,
        public ?float $segmentStartTime = null,
        public ?float $segmentEndTime = null,

        // Title generation strategy
        public TitleGenerationStrategy $titleStrategy = TitleGenerationStrategy::AI_WITH_FALLBACK,

        // Override defaults
        public ?string $preacher = null,
        public ?string $service = null,
        public ?string $date = null,
        public ?string $customTitle = null,

        // ID3 metadata fields (extracted from audio file tags)
        public ?string $id3Title = null,
        public ?string $id3Preacher = null,
        public ?string $id3Series = null,
        public ?string $id3Reference = null,
    ) {}

    /**
     * Create options for audio upload processing
     */
    public static function fromAudioUpload(MediaProcessingLog $log, array $aiAnalysis): self
    {
        return new self(
            audioFilePath: $log->source_file_path,
            originalFilename: $log->original_filename,
            sourceType: 'audio_upload',
            transcriptFilePath: $log->transcript_file_path,
            aiAnalysis: $aiAnalysis,
            titleStrategy: TitleGenerationStrategy::AI_WITH_FALLBACK,
        );
    }

    /**
     * Create options for video upload processing
     */
    public static function fromVideoUpload(MediaProcessingLog $log, array $aiAnalysis): self
    {
        return new self(
            audioFilePath: $log->audio_file_path,
            originalFilename: $log->original_filename,
            sourceType: 'video_upload',
            videoFilePath: $log->video_file_path,
            transcriptFilePath: $log->transcript_file_path,
            aiAnalysis: $aiAnalysis,
            titleStrategy: TitleGenerationStrategy::AI_WITH_FALLBACK,
        );
    }

    /**
     * Create options for livestream processing
     */
    public static function fromLivestream(MediaProcessingLog $log, array $metadata): self
    {
        return new self(
            audioFilePath: $log->audio_file_path,
            originalFilename: $metadata['original_filename'] ?? $log->original_filename,
            sourceType: 'livestream',
            videoFilePath: $metadata['video_file_path'] ?? null,
            livestreamProcessingId: $metadata['livestream_processing_id'] ?? $log->processing_id,
            segmentStartTime: $metadata['segment_start_time'] ?? null,
            segmentEndTime: $metadata['segment_end_time'] ?? null,
            titleStrategy: TitleGenerationStrategy::FILENAME_ONLY,
        );
    }
}
