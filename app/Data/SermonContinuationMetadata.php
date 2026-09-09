<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A section that carries part of a sermon delivered around an intervening item.
 *
 * The detector types these sections `other` and its own banked notes say what
 * they are — "This is a continuation of the single sermon, separated by a
 * congregational song." The information was never lost, only discarded, so this
 * records the sentence that admitted the section rather than re-deriving a
 * judgement at plan time (P8-Q15).
 *
 * It is deliberately *not* a `sermon` section type. `ServiceStructureValidator`
 * hard-fails a second sermon section, and four historic services require exactly
 * one independently of the validator, so retyping would break them all. The
 * section stays what it is and the extraction plan spans it.
 *
 * `ofSectionId` names the primary sermon this continues. A marker naming some
 * other run's section, or a sermon this run does not hold, admits nothing: the
 * resolver matches on it rather than trusting the marker's presence.
 */
final readonly class SermonContinuationMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?int $ofSectionId = null,
        public ?string $evidence = null,
        public ?string $source = null,
        public array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        return new self(
            ofSectionId: self::intOrNull($value['of_section_id'] ?? null),
            evidence: self::stringOrNull($value['evidence'] ?? null),
            source: self::stringOrNull($value['source'] ?? null),
            raw: $value,
        );
    }

    /**
     * Whether this marker admits the section as a continuation of the given sermon.
     *
     * Both the sermon it names and the evidence that named it are required. A
     * marker with no evidence records no reason, and a reader could not later
     * tell a detector-attested continuation from one somebody assumed.
     */
    public function continues(int $sermonSectionId): bool
    {
        return $this->ofSectionId === $sermonSectionId
            && $this->evidence !== null
            && trim($this->evidence) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->ofSectionId !== null) {
            $data['of_section_id'] = $this->ofSectionId;
        }

        if ($this->evidence !== null) {
            $data['evidence'] = $this->evidence;
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
