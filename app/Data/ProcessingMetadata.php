<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ProcessingMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>|null  $speakerIdentification
     * @param  array<string, mixed>|null  $videoQuality
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?ProcessingId3Metadata $id3Metadata = null,
        public ?ProcessingManualReviewMetadata $manualReview = null,
        public ?array $speakerIdentification = null,
        public ?array $videoQuality = null,
        public ?string $videoProcessingMode = null,
        public ?bool $trimRequested = null,
        public ?HistoricEditorialFacts $editorialFacts = null,
        public array $raw = [],
    ) {}

    public static function fromArray(mixed $value): self
    {
        $payload = self::arrayValue($value);

        return new self(
            id3Metadata: ProcessingId3Metadata::fromArray($payload['id3_metadata'] ?? null),
            manualReview: ProcessingManualReviewMetadata::fromArray($payload['manual_review'] ?? null),
            speakerIdentification: ($payload['speaker_identification'] ?? null) && is_array($payload['speaker_identification'])
                ? $payload['speaker_identification']
                : null,
            videoQuality: ($payload['video_quality'] ?? null) && is_array($payload['video_quality'])
                ? $payload['video_quality']
                : null,
            videoProcessingMode: self::stringOrNull($payload['video_processing_mode'] ?? null),
            trimRequested: self::boolOrNull($payload['trim_requested'] ?? null),
            editorialFacts: HistoricEditorialFacts::fromArray(
                $payload['historic_import']['editorial_facts'] ?? null
            ),
            raw: $payload,
        );
    }

    /**
     * `editorialFacts` is deliberately absent below: it is a read-only view onto
     * `historic_import.editorial_facts`, which `raw` already round-trips whole.
     * Writing it back would rebuild that block and drop its sibling manifest,
     * staging and operation keys.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->id3Metadata instanceof ProcessingId3Metadata) {
            $data['id3_metadata'] = $this->id3Metadata->toArray();
        }

        if ($this->manualReview instanceof ProcessingManualReviewMetadata) {
            $data['manual_review'] = $this->manualReview->toArray();
        }

        if ($this->speakerIdentification !== null) {
            $data['speaker_identification'] = $this->speakerIdentification;
        }

        if ($this->videoQuality !== null) {
            $data['video_quality'] = $this->videoQuality;
        }

        if ($this->videoProcessingMode !== null) {
            $data['video_processing_mode'] = $this->videoProcessingMode;
        }

        if ($this->trimRequested !== null) {
            $data['trim_requested'] = $this->trimRequested;
        }

        return $data;
    }
}
