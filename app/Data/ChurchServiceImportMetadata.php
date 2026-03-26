<?php

declare(strict_types=1);

namespace App\Data;

final class ChurchServiceImportMetadata extends JsonData
{
    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $reviewTriggers
     * @param  list<array<string, mixed>>  $canonicalConflictHistory
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?float $confidenceScore = null,
        public readonly ?string $parseMethod = null,
        public readonly array $warnings = [],
        public readonly array $reviewTriggers = [],
        public readonly array $canonicalConflictHistory = [],
        public readonly ?ChurchServiceManualReviewMetadata $manualReview = null,
        public readonly ?ChurchServiceCanonicalConflictMetadata $canonicalConflict = null,
        public readonly ?PendingStructureMergeMetadata $pendingStructureMerge = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): self
    {
        $payload = self::arrayValue($value);

        return new self(
            confidenceScore: self::floatOrNull($payload['confidence_score'] ?? null),
            parseMethod: self::stringOrNull($payload['parse_method'] ?? null),
            warnings: self::stringList($payload['warnings'] ?? null),
            reviewTriggers: self::stringList($payload['review_triggers'] ?? null),
            canonicalConflictHistory: array_values(array_filter(self::arrayValue($payload['canonical_conflict_history'] ?? null), 'is_array')),
            manualReview: ChurchServiceManualReviewMetadata::fromArray($payload['manual_review'] ?? null),
            canonicalConflict: ChurchServiceCanonicalConflictMetadata::fromArray($payload['canonical_conflict'] ?? null),
            pendingStructureMerge: PendingStructureMergeMetadata::fromArray($payload['pending_structure_merge'] ?? null),
            raw: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->confidenceScore !== null) {
            $data['confidence_score'] = $this->confidenceScore;
        }

        if ($this->parseMethod !== null) {
            $data['parse_method'] = $this->parseMethod;
        }

        if ($this->warnings !== []) {
            $data['warnings'] = $this->warnings;
        }

        if ($this->reviewTriggers !== []) {
            $data['review_triggers'] = $this->reviewTriggers;
        }

        if ($this->canonicalConflictHistory !== []) {
            $data['canonical_conflict_history'] = $this->canonicalConflictHistory;
        }

        if ($this->manualReview instanceof ChurchServiceManualReviewMetadata) {
            $data['manual_review'] = $this->manualReview->toArray();
        }

        if ($this->canonicalConflict instanceof ChurchServiceCanonicalConflictMetadata) {
            $data['canonical_conflict'] = $this->canonicalConflict->toArray();
        }

        if ($this->pendingStructureMerge instanceof PendingStructureMergeMetadata) {
            $data['pending_structure_merge'] = $this->pendingStructureMerge->toArray();
        }

        return $data;
    }
}
