<?php

declare(strict_types=1);

namespace App\Data;

final class ChurchServiceCanonicalConflictMetadata extends JsonData
{
    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $detectedAt = null,
        public readonly ?string $incomingSource = null,
        public readonly ?bool $reviewReopened = null,
        public readonly ?bool $reviewedPreviously = null,
        public readonly ?bool $canonicalChanged = null,
        public readonly array $changes = [],
        public readonly array $conflicts = [],
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        return new self(
            detectedAt: self::stringOrNull($value['detected_at'] ?? null),
            incomingSource: self::stringOrNull($value['incoming_source'] ?? null),
            reviewReopened: self::boolOrNull($value['review_reopened'] ?? null),
            reviewedPreviously: self::boolOrNull($value['reviewed_previously'] ?? null),
            canonicalChanged: self::boolOrNull($value['canonical_changed'] ?? null),
            changes: array_values(array_filter(self::arrayValue($value['changes'] ?? null), 'is_array')),
            conflicts: array_values(array_filter(self::arrayValue($value['conflicts'] ?? null), 'is_array')),
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->detectedAt !== null) {
            $data['detected_at'] = $this->detectedAt;
        }

        if ($this->incomingSource !== null) {
            $data['incoming_source'] = $this->incomingSource;
        }

        if ($this->reviewReopened !== null) {
            $data['review_reopened'] = $this->reviewReopened;
        }

        if ($this->reviewedPreviously !== null) {
            $data['reviewed_previously'] = $this->reviewedPreviously;
        }

        if ($this->canonicalChanged !== null) {
            $data['canonical_changed'] = $this->canonicalChanged;
        }

        if ($this->changes !== []) {
            $data['changes'] = $this->changes;
        }

        if ($this->conflicts !== []) {
            $data['conflicts'] = $this->conflicts;
        }

        return $data;
    }
}
