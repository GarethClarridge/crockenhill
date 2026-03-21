<?php

declare(strict_types=1);

namespace App\Data;

final class ServiceSectionMetadata extends JsonData
{
    /**
     * @param  list<string>  $reviewFlags
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $confidenceLevel = null,
        public readonly ?string $classificationMode = null,
        public readonly ?string $confidenceSource = null,
        public readonly ?float $confidenceScore = null,
        public readonly ?string $reviewReason = null,
        public readonly array $reviewFlags = [],
        public readonly ?string $transcript = null,
        public readonly ?int $songId = null,
        public readonly ?string $readingReference = null,
        public readonly ?SectionOosAlignment $oosAlignment = null,
        public readonly ?ChildrensTalkSpeakerMetadata $childrensTalkSpeaker = null,
        public readonly ?SectionPublicationMetadata $publication = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): self
    {
        $payload = self::arrayValue($value);

        return new self(
            confidenceLevel: self::stringOrNull($payload['confidence_level'] ?? null),
            classificationMode: self::stringOrNull($payload['classification_mode'] ?? null),
            confidenceSource: self::stringOrNull($payload['confidence_source'] ?? null),
            confidenceScore: self::floatOrNull($payload['confidence_score'] ?? null),
            reviewReason: self::stringOrNull($payload['review_reason'] ?? null),
            reviewFlags: self::stringList($payload['review_flags'] ?? null),
            transcript: self::stringOrNull($payload['transcript'] ?? null),
            songId: self::intOrNull($payload['song_id'] ?? null),
            readingReference: self::stringOrNull($payload['reading_reference'] ?? null),
            oosAlignment: SectionOosAlignment::fromArray($payload['oos_alignment'] ?? null),
            childrensTalkSpeaker: ChildrensTalkSpeakerMetadata::fromArray($payload['childrens_talk_speaker'] ?? null),
            publication: SectionPublicationMetadata::fromArray($payload['publication'] ?? null),
            raw: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->confidenceLevel !== null) {
            $data['confidence_level'] = $this->confidenceLevel;
        }

        if ($this->classificationMode !== null) {
            $data['classification_mode'] = $this->classificationMode;
        }

        if ($this->confidenceSource !== null) {
            $data['confidence_source'] = $this->confidenceSource;
        }

        if ($this->confidenceScore !== null) {
            $data['confidence_score'] = $this->confidenceScore;
        }

        if ($this->reviewReason !== null) {
            $data['review_reason'] = $this->reviewReason;
        }

        if ($this->reviewFlags !== []) {
            $data['review_flags'] = $this->reviewFlags;
        }

        if ($this->transcript !== null) {
            $data['transcript'] = $this->transcript;
        }

        if ($this->songId !== null) {
            $data['song_id'] = $this->songId;
        }

        if ($this->readingReference !== null) {
            $data['reading_reference'] = $this->readingReference;
        }

        if ($this->oosAlignment instanceof SectionOosAlignment) {
            $data['oos_alignment'] = $this->oosAlignment->toArray();
        }

        if ($this->childrensTalkSpeaker instanceof ChildrensTalkSpeakerMetadata) {
            $data['childrens_talk_speaker'] = $this->childrensTalkSpeaker->toArray();
        }

        if ($this->publication instanceof SectionPublicationMetadata) {
            $data['publication'] = $this->publication->toArray();
        }

        return $data;
    }
}
