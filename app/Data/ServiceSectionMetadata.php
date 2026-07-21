<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ServiceSectionMetadata extends JsonData
{
    /**
     * @param  list<string>  $reviewFlags
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $confidenceLevel = null,
        public ?string $classificationMode = null,
        public ?string $confidenceSource = null,
        public ?float $confidenceScore = null,
        public ?string $reviewReason = null,
        public ?string $summary = null,
        public array $reviewFlags = [],
        public ?string $transcript = null,
        public ?int $songId = null,
        public ?string $readingReference = null,
        public ?SectionOosAlignment $oosAlignment = null,
        public ?ChildrensTalkSpeakerMetadata $childrensTalkSpeaker = null,
        public ?SectionPublicationMetadata $publication = null,
        public array $raw = [],
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
            summary: self::stringOrNull($payload['summary'] ?? null),
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

        if ($this->summary !== null) {
            $data['summary'] = $this->summary;
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
