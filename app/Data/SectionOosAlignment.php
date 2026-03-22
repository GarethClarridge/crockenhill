<?php

declare(strict_types=1);

namespace App\Data;

final class SectionOosAlignment extends JsonData
{
    /**
     * @param  array<string, mixed>|null  $presentationInference
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $songMatchType = null,
        public readonly ?float $songMatchScore = null,
        public readonly ?string $songMatchStrategy = null,
        public readonly ?string $songTitleMatched = null,
        public readonly ?string $reclassifiedFrom = null,
        public readonly ?string $reclassifiedBy = null,
        public readonly ?int $matchedItemId = null,
        public readonly ?string $matchedItemType = null,
        public readonly ?string $matchedItemTitle = null,
        public readonly ?string $mismatchReason = null,
        public readonly ?int $expectedItemId = null,
        public readonly ?string $expectedItemTitle = null,
        public readonly ?string $expectedSectionType = null,
        public readonly ?float $baseConfidence = null,
        public readonly ?bool $baseNeedsManualReview = null,
        public readonly ?string $baseTitle = null,
        public readonly ?int $baseChurchServiceItemId = null,
        public readonly ?array $presentationInference = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $presentationInference = $value['presentation_inference'] ?? null;

        return new self(
            songMatchType: self::stringOrNull($value['song_match_type'] ?? null),
            songMatchScore: self::floatOrNull($value['song_match_score'] ?? null),
            songMatchStrategy: self::stringOrNull($value['song_match_strategy'] ?? null),
            songTitleMatched: self::stringOrNull($value['song_title_matched'] ?? null),
            reclassifiedFrom: self::stringOrNull($value['reclassified_from'] ?? null),
            reclassifiedBy: self::stringOrNull($value['reclassified_by'] ?? null),
            matchedItemId: self::intOrNull($value['matched_item_id'] ?? null),
            matchedItemType: self::stringOrNull($value['matched_item_type'] ?? null),
            matchedItemTitle: self::stringOrNull($value['matched_item_title'] ?? null),
            mismatchReason: self::stringOrNull($value['mismatch_reason'] ?? null),
            expectedItemId: self::intOrNull($value['expected_item_id'] ?? null),
            expectedItemTitle: self::stringOrNull($value['expected_item_title'] ?? null),
            expectedSectionType: self::stringOrNull($value['expected_section_type'] ?? null),
            baseConfidence: self::floatOrNull($value['base_confidence'] ?? null),
            baseNeedsManualReview: self::boolOrNull($value['base_needs_manual_review'] ?? null),
            baseTitle: self::stringOrNull($value['base_title'] ?? null),
            baseChurchServiceItemId: self::intOrNull($value['base_church_service_item_id'] ?? null),
            presentationInference: is_array($presentationInference) ? $presentationInference : null,
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;
        unset($data['song_match_type'], $data['matched_item_id'], $data['expected_item_id']);

        if ($this->songMatchScore !== null) {
            $data['song_match_score'] = $this->songMatchScore;
        }

        if ($this->songMatchStrategy !== null) {
            $data['song_match_strategy'] = $this->songMatchStrategy;
        }

        if ($this->songTitleMatched !== null) {
            $data['song_title_matched'] = $this->songTitleMatched;
        }

        if ($this->reclassifiedFrom !== null) {
            $data['reclassified_from'] = $this->reclassifiedFrom;
        }

        if ($this->reclassifiedBy !== null) {
            $data['reclassified_by'] = $this->reclassifiedBy;
        }

        if ($this->matchedItemType !== null) {
            $data['matched_item_type'] = $this->matchedItemType;
        }

        if ($this->matchedItemTitle !== null) {
            $data['matched_item_title'] = $this->matchedItemTitle;
        }

        if ($this->mismatchReason !== null) {
            $data['mismatch_reason'] = $this->mismatchReason;
        }

        if ($this->expectedItemTitle !== null) {
            $data['expected_item_title'] = $this->expectedItemTitle;
        }

        if ($this->expectedSectionType !== null) {
            $data['expected_section_type'] = $this->expectedSectionType;
        }

        if ($this->baseConfidence !== null) {
            $data['base_confidence'] = $this->baseConfidence;
        }

        if ($this->baseNeedsManualReview !== null) {
            $data['base_needs_manual_review'] = $this->baseNeedsManualReview;
        }

        if ($this->baseTitle !== null) {
            $data['base_title'] = $this->baseTitle;
        }

        if ($this->baseChurchServiceItemId !== null) {
            $data['base_church_service_item_id'] = $this->baseChurchServiceItemId;
        }

        if ($this->presentationInference !== null) {
            $data['presentation_inference'] = $this->presentationInference;
        }

        return $data;
    }
}
