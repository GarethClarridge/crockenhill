<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ServiceSectionType;

/**
 * One typed section of a detected service structure: an LLM boundary proposal
 * in whole-recording seconds, awaiting the deterministic gate (validation,
 * silence-snapping) before it may reach persistence.
 */
final readonly class ServiceStructureSection extends JsonData
{
    public const REVIEW_FLAG_UNKNOWN_TYPE = 'unknown_section_type';

    /**
     * @param  list<string>  $notes
     * @param  list<string>  $reviewFlags
     */
    public function __construct(
        public ServiceSectionType $type,
        public ?string $title,
        public float $startTime,
        public float $endTime,
        public float $confidence,
        public ?int $oosItemId,
        public ?string $songTitle,
        public ?string $readingReference,
        public array $notes = [],
        public array $reviewFlags = [],
    ) {}

    /**
     * Build a section from a raw detector payload entry.
     *
     * Returns null when the entry has no usable time boundaries. An unknown
     * section type is normalised to `other` and flagged for review rather
     * than rejected — the run continues, a human checks the label.
     */
    public static function fromArray(mixed $value): ?self
    {
        $payload = self::arrayValue($value);

        $startTime = self::floatOrNull($payload['start_time'] ?? null);
        $endTime = self::floatOrNull($payload['end_time'] ?? null);

        if ($startTime === null || $endTime === null) {
            return null;
        }

        $requestedType = self::stringOrNull($payload['type'] ?? null);
        $type = $requestedType === null ? null : ServiceSectionType::tryFrom($requestedType);

        $notes = self::stringList($payload['notes'] ?? []);
        $reviewFlags = self::stringList($payload['review_flags'] ?? []);

        if ($type === null) {
            $type = ServiceSectionType::Other;

            if (! in_array(self::REVIEW_FLAG_UNKNOWN_TYPE, $reviewFlags, true)) {
                $reviewFlags[] = self::REVIEW_FLAG_UNKNOWN_TYPE;
            }

            if ($requestedType !== null) {
                $notes[] = "Detector requested unknown section type \"{$requestedType}\".";
            }
        }

        $confidence = self::floatOrNull($payload['confidence'] ?? null) ?? 0.0;

        return new self(
            type: $type,
            title: self::stringOrNull($payload['title'] ?? null),
            startTime: max(0.0, $startTime),
            endTime: max(max(0.0, $startTime), $endTime),
            confidence: max(0.0, min(1.0, $confidence)),
            oosItemId: self::intOrNull($payload['oos_item_id'] ?? null),
            songTitle: self::stringOrNull($payload['song_title'] ?? null),
            readingReference: self::stringOrNull($payload['reading_reference'] ?? null),
            notes: $notes,
            reviewFlags: $reviewFlags,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'confidence' => $this->confidence,
            'oos_item_id' => $this->oosItemId,
            'song_title' => $this->songTitle,
            'reading_reference' => $this->readingReference,
            'notes' => $this->notes,
            'review_flags' => $this->reviewFlags,
        ];
    }

    public function duration(): float
    {
        return $this->endTime - $this->startTime;
    }

    /**
     * A copy with adjusted boundaries (silence-snapping) — extra notes record
     * why the times moved.
     *
     * @param  list<string>  $additionalNotes
     */
    public function withTimes(float $startTime, float $endTime, array $additionalNotes = []): self
    {
        return new self(
            type: $this->type,
            title: $this->title,
            startTime: $startTime,
            endTime: $endTime,
            confidence: $this->confidence,
            oosItemId: $this->oosItemId,
            songTitle: $this->songTitle,
            readingReference: $this->readingReference,
            notes: array_values([...$this->notes, ...$additionalNotes]),
            reviewFlags: $this->reviewFlags,
        );
    }

    /**
     * A copy carrying additional review flags (deduplicated).
     *
     * @param  list<string>  $flags
     */
    public function withReviewFlags(array $flags): self
    {
        return new self(
            type: $this->type,
            title: $this->title,
            startTime: $this->startTime,
            endTime: $this->endTime,
            confidence: $this->confidence,
            oosItemId: $this->oosItemId,
            songTitle: $this->songTitle,
            readingReference: $this->readingReference,
            notes: $this->notes,
            reviewFlags: array_values(array_unique([...$this->reviewFlags, ...$flags])),
        );
    }
}
