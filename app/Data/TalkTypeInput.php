<?php

declare(strict_types=1);

namespace App\Data;

/** Evidence is classifier-visible; provenance and the baseline are audit-only. */
final readonly class TalkTypeInput extends JsonData
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $versions  Includes classifier/rule/prompt/schema versions and model settings.
     * @param  list<string>  $ineligibleReasons
     */
    public function __construct(
        public int $sectionId,
        public array $evidence,
        public array $provenance,
        public array $versions = [],
        public ?string $structureProposed = null,
        public array $ineligibleReasons = [],
    ) {}

    public function inputHash(): string
    {
        return hash('sha256', json_encode(self::canonical([
            'evidence' => $this->evidence,
            'provenance' => $this->provenance,
            'versions' => $this->versions,
        ]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['section_id' => $this->sectionId, 'evidence' => $this->evidence,
            'provenance' => $this->provenance, 'versions' => $this->versions,
            'structure_proposed' => $this->structureProposed, 'ineligible_reasons' => $this->ineligibleReasons,
            'input_hash' => $this->inputHash()];
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
