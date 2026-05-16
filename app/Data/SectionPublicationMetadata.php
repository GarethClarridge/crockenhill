<?php

declare(strict_types=1);

namespace App\Data;

final readonly class SectionPublicationMetadata extends JsonData
{
    /**
     * @param  list<array<string, mixed>>  $batchApprovals
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $approvedSignature = null,
        public ?string $approvedAt = null,
        public array $batchApprovals = [],
        public array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        return new self(
            approvedSignature: self::stringOrNull($value['approved_signature'] ?? null),
            approvedAt: self::stringOrNull($value['approved_at'] ?? null),
            batchApprovals: array_values(array_filter(self::arrayValue($value['batch_approvals'] ?? null), 'is_array')),
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->approvedSignature !== null) {
            $data['approved_signature'] = $this->approvedSignature;
        }

        if ($this->approvedAt !== null) {
            $data['approved_at'] = $this->approvedAt;
        }

        if ($this->batchApprovals !== []) {
            $data['batch_approvals'] = $this->batchApprovals;
        }

        return $data;
    }
}
