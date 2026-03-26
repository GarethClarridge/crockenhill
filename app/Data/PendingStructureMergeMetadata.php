<?php

declare(strict_types=1);

namespace App\Data;

final class PendingStructureMergeMetadata extends JsonData
{
    /**
     * @param  array{current: string, incoming: string}  $confidence
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<array<string, mixed>>  $proposedItems
     * @param  array{auto_merge: list<int>, review_required: list<int>, unmatched_incoming: list<int>}  $classification
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $incomingSource = null,
        public readonly ?string $createdAt = null,
        public readonly array $confidence = ['current' => 'unknown', 'incoming' => 'unknown'],
        public readonly array $conflicts = [],
        public readonly array $proposedItems = [],
        public readonly array $classification = ['auto_merge' => [], 'review_required' => [], 'unmatched_incoming' => []],
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $confidence = is_array($value['confidence'] ?? null) ? $value['confidence'] : [];

        return new self(
            incomingSource: self::stringOrNull($value['incoming_source'] ?? null),
            createdAt: self::stringOrNull($value['created_at'] ?? null),
            confidence: [
                'current' => is_string($confidence['current'] ?? null) ? $confidence['current'] : 'unknown',
                'incoming' => is_string($confidence['incoming'] ?? null) ? $confidence['incoming'] : 'unknown',
            ],
            conflicts: array_values(array_filter(self::arrayValue($value['conflicts'] ?? null), 'is_array')),
            proposedItems: array_values(array_filter(self::arrayValue($value['proposed_items'] ?? null), 'is_array')),
            classification: self::parseClassification($value['classification'] ?? null),
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->incomingSource !== null) {
            $data['incoming_source'] = $this->incomingSource;
        }

        if ($this->createdAt !== null) {
            $data['created_at'] = $this->createdAt;
        }

        $data['confidence'] = $this->confidence;

        if ($this->conflicts !== []) {
            $data['conflicts'] = $this->conflicts;
        }

        if ($this->proposedItems !== []) {
            $data['proposed_items'] = $this->proposedItems;
        }

        if ($this->classification !== ['auto_merge' => [], 'review_required' => [], 'unmatched_incoming' => []]) {
            $data['classification'] = $this->classification;
        }

        return $data;
    }

    /**
     * @return array{auto_merge: list<int>, review_required: list<int>, unmatched_incoming: list<int>}
     */
    private static function parseClassification(mixed $value): array
    {
        if (! is_array($value)) {
            return ['auto_merge' => [], 'review_required' => [], 'unmatched_incoming' => []];
        }

        return [
            'auto_merge' => array_values(array_filter(
                self::arrayValue($value['auto_merge'] ?? null),
                'is_int'
            )),
            'review_required' => array_values(array_filter(
                self::arrayValue($value['review_required'] ?? null),
                'is_int'
            )),
            'unmatched_incoming' => array_values(array_filter(
                self::arrayValue($value['unmatched_incoming'] ?? null),
                'is_int'
            )),
        ];
    }
}
