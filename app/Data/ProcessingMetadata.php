<?php

declare(strict_types=1);

namespace App\Data;

final class ProcessingMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>|null  $speakerIdentification
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?ProcessingId3Metadata $id3Metadata = null,
        public readonly ?ProcessingManualReviewMetadata $manualReview = null,
        public readonly ?array $speakerIdentification = null,
        public readonly array $raw = [],
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
            raw: $payload,
        );
    }

    /**
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

        return $data;
    }
}
