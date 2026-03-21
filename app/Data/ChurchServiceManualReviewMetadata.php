<?php

declare(strict_types=1);

namespace App\Data;

final class ChurchServiceManualReviewMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $reviewedAt = null,
        public readonly ?int $reviewedByUserId = null,
        public readonly ?string $reopenedAt = null,
        public readonly ?string $reopenedBySource = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        return new self(
            reviewedAt: self::stringOrNull($value['reviewed_at'] ?? null),
            reviewedByUserId: self::intOrNull($value['reviewed_by_user_id'] ?? null),
            reopenedAt: self::stringOrNull($value['reopened_at'] ?? null),
            reopenedBySource: self::stringOrNull($value['reopened_by_source'] ?? null),
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->reviewedAt !== null) {
            $data['reviewed_at'] = $this->reviewedAt;
        }

        if ($this->reviewedByUserId !== null) {
            $data['reviewed_by_user_id'] = $this->reviewedByUserId;
        }

        if ($this->reopenedAt !== null) {
            $data['reopened_at'] = $this->reopenedAt;
        }

        if ($this->reopenedBySource !== null) {
            $data['reopened_by_source'] = $this->reopenedBySource;
        }

        return $data;
    }
}
