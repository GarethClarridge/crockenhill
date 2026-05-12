<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use App\Traits\ReadsSectionMetadata;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class StructuralSectionAligner
{
    use ReadsSectionMetadata;

    public function __construct(
        private readonly PresentationItemClassifier $presentationItemClassifier,
        private readonly SectionAlignmentBaselineRestorer $baselineRestorer,
    ) {}

    /**
     * Walk structural sections and items in parallel, applying matches and recording mismatches.
     *
     * Uses lookahead to handle ordering gaps without inflating the mismatch count for sections
     * that eventually align further along in the sequence. Returns the total count of structural
     * mismatches (used for review-trigger evaluation).
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  EloquentCollection<int, ChurchServiceItem>  $items
     */
    public function align(EloquentCollection $sections, EloquentCollection $items): int
    {
        $presentationClassification = $this->presentationItemClassifier->classify($items);
        $presentationDecisions = $presentationClassification['decisions'];
        $ambiguousChildrensTalk = $presentationClassification['childrens_talk_count'] > 1;

        /** @var Collection<int, ServiceSection> $structuralSections */
        $structuralSections = $sections
            ->filter(fn (ServiceSection $section): bool => $section->section_type !== ServiceSectionType::SONG)
            ->values();

        /** @var Collection<int, ChurchServiceItem> $structuralItems */
        $structuralItems = $items
            ->filter(fn (ChurchServiceItem $item): bool => $this->resolvedItemType($item, $presentationDecisions) !== ServiceSectionType::SONG)
            ->values();

        $sectionIndex = 0;
        $itemIndex = 0;
        $mismatchCount = 0;

        while ($sectionIndex < $structuralSections->count() || $itemIndex < $structuralItems->count()) {
            /** @var ServiceSection|null $section */
            $section = $structuralSections->get($sectionIndex);
            /** @var ChurchServiceItem|null $item */
            $item = $structuralItems->get($itemIndex);

            if (! $section instanceof ServiceSection) {
                $mismatchCount++;
                $itemIndex++;

                continue;
            }

            if (! $item instanceof ChurchServiceItem) {
                $this->markMismatch($section, null, 'unexpected_detected_section');
                $mismatchCount++;
                $sectionIndex++;

                continue;
            }

            $expectedType = $this->resolvedItemType($item, $presentationDecisions);

            if ($section->section_type === $expectedType) {
                $this->applyMatchedItem($section, $item, 0.35);

                if ($expectedType === ServiceSectionType::BIBLE_READING) {
                    $metadata = $this->metadata($section);
                    $metadata['reading_reference'] = $item->title;
                    $section->metadata = ServiceSectionMetadata::fromArray($metadata);
                } elseif (($section->title === null || trim($section->title) === '') && $expectedType !== ServiceSectionType::SERMON) {
                    $section->title = $item->title;
                }

                // Attach presentation inference trace even on a direct type match
                $decision = $presentationDecisions[$item->id] ?? null;
                if (is_array($decision)) {
                    $this->applyPresentationDecisionMetadata($section, $decision, $ambiguousChildrensTalk);
                }

                $sectionIndex++;
                $itemIndex++;

                continue;
            }

            if ($section->section_type === ServiceSectionType::OTHER && $this->isOosReclassifiableType($expectedType)) {
                $section->section_type = $expectedType;
                $this->applyMatchedItem($section, $item, 0.35);

                $metadata = $this->metadata($section);
                $metadata['oos_alignment'] = array_merge($metadata['oos_alignment'] ?? [], [
                    'reclassified_from' => ServiceSectionType::OTHER->value,
                    'reclassified_by' => 'oos_alignment',
                ]);
                $section->metadata = ServiceSectionMetadata::fromArray($metadata);
                $section->title = $item->title;

                // Apply evidence-aware review flags for presentation items
                $decision = $presentationDecisions[$item->id] ?? null;
                if (is_array($decision)) {
                    $this->applyPresentationDecisionMetadata($section, $decision, $ambiguousChildrensTalk);
                }

                $sectionIndex++;
                $itemIndex++;

                continue;
            }

            // Attach weak-evidence presentation hints even when the section doesn't get reclassified
            $decision = $presentationDecisions[$item->id] ?? null;
            if (is_array($decision) && $decision['evidence'] === 'weak') {
                $metadata = $this->metadata($section);
                $metadata['oos_alignment'] = array_merge($metadata['oos_alignment'] ?? [], [
                    'presentation_inference' => [
                        'resolved_type' => $decision['resolved_type']->value,
                        'suspected_type' => $decision['suspected_type']?->value,
                        'evidence' => $decision['evidence'],
                        'reason' => $decision['reason'],
                    ],
                ]);
                $section->metadata = ServiceSectionMetadata::fromArray($metadata);
            }

            if ($this->remainingSectionsContainType($structuralSections, $sectionIndex + 1, $expectedType)) {
                $this->markMismatch($section, $item, 'unexpected_detected_section');
                $mismatchCount++;
                $sectionIndex++;

                continue;
            }

            if ($this->remainingItemsContainType($structuralItems, $itemIndex + 1, $section->section_type)) {
                $mismatchCount++;
                $itemIndex++;

                continue;
            }

            $this->markMismatch($section, $item, 'oos_type_mismatch');
            $mismatchCount++;
            $sectionIndex++;
            $itemIndex++;
        }

        return $mismatchCount;
    }

    /**
     * Apply presentation inference metadata and review flags based on the decision payload.
     *
     * Strong childrens-talk decisions require manual review and a review flag.
     * Ambiguous decisions (multiple childrens-talk items resolved) also add the ambiguity flag.
     * Weak decisions only write a metadata hint and never set needs_manual_review.
     *
     * @param  array{
     *     resolved_type: ServiceSectionType,
     *     suspected_type: ServiceSectionType|null,
     *     evidence: 'explicit'|'strong'|'weak',
     *     requires_review: bool,
     *     review_flag: string|null,
     *     reason: string
     * }  $decision
     */
    private function applyPresentationDecisionMetadata(
        ServiceSection $section,
        array $decision,
        bool $ambiguousChildrensTalk
    ): void {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($metadata['oos_alignment'] ?? [], [
            'presentation_inference' => [
                'resolved_type' => $decision['resolved_type']->value,
                'suspected_type' => $decision['suspected_type']?->value,
                'evidence' => $decision['evidence'],
                'reason' => $decision['reason'],
            ],
        ]);

        if ($decision['requires_review'] && $decision['review_flag'] !== null) {
            $reviewFlags = $this->reviewFlags($metadata);
            $reviewFlags[] = $decision['review_flag'];

            if ($ambiguousChildrensTalk && $decision['resolved_type'] === ServiceSectionType::CHILDRENS_TALK) {
                $reviewFlags[] = 'ambiguous_childrens_talk';
                $metadata['review_flags'] = array_values(array_unique($reviewFlags));
                $metadata['review_reason'] = 'ambiguous_childrens_talk';
            } else {
                $metadata['review_flags'] = array_values(array_unique($reviewFlags));
                if (! array_key_exists('review_reason', $metadata) || in_array($metadata['review_reason'], SectionAlignmentBaselineRestorer::OOS_REVIEW_REASONS, true)) {
                    $metadata['review_reason'] = $decision['review_flag'];
                }
            }

            $section->needs_manual_review = true;
        } elseif ($ambiguousChildrensTalk && $decision['resolved_type'] === ServiceSectionType::CHILDRENS_TALK) {
            $reviewFlags = $this->reviewFlags($metadata);
            $reviewFlags[] = 'ambiguous_childrens_talk';
            $metadata['review_flags'] = array_values(array_unique($reviewFlags));
            $metadata['review_reason'] = 'ambiguous_childrens_talk';
            $section->needs_manual_review = true;
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }

    private function applyMatchedItem(ServiceSection $section, ChurchServiceItem $item, float $confidenceDelta): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baselineRestorer->baseAlignmentMetadata($section), [
            'matched_item_type' => $item->type,
            'matched_item_title' => $item->title,
        ]);
        unset($metadata['oos_alignment']['mismatch_reason']);

        $reviewFlags = $this->baselineRestorer->clearOosReviewFlags($this->reviewFlags($metadata));

        $metadata['review_flags'] = $reviewFlags;

        if ($reviewFlags === []) {
            unset($metadata['review_reason']);
        }

        $section->church_service_item_id = $item->id;
        $section->matched_item_id = $item->id;
        $section->expected_item_id = null;
        $section->needs_manual_review = $section->needs_manual_review || $this->hasBlockingReviewFlag($reviewFlags);
        $section->confidence = ServiceSectionConfidence::clamp(
            ServiceSectionConfidence::increase(
                ServiceSectionConfidence::resolve($section->confidence, $metadata),
                $confidenceDelta
            )
        );
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }

    private function markMismatch(ServiceSection $section, ?ChurchServiceItem $item, string $reason): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baselineRestorer->baseAlignmentMetadata($section), [
            'mismatch_reason' => $reason,
            'expected_item_title' => $item?->title,
            'expected_section_type' => $item instanceof ChurchServiceItem ? $this->resolvedItemType($item)->value : null,
        ]);

        $reviewFlags = $this->reviewFlags($metadata);
        $reviewFlags[] = 'oos_structure_mismatch';
        $metadata['review_flags'] = array_values(array_unique($reviewFlags));
        $metadata['review_reason'] = 'oos_structure_mismatch';

        $section->needs_manual_review = true;
        $section->matched_item_id = null;
        $section->expected_item_id = $item?->id;
        $section->confidence = ServiceSectionConfidence::decrease(
            ServiceSectionConfidence::resolve($section->confidence, $metadata),
            0.20
        );
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }

    /**
     * @param  array<int, array{
     *     resolved_type: ServiceSectionType,
     *     suspected_type: ServiceSectionType|null,
     *     evidence: 'explicit'|'strong'|'weak',
     *     requires_review: bool,
     *     review_flag: string|null,
     *     reason: string
     * }>  $presentationDecisions
     */
    private function resolvedItemType(ChurchServiceItem $item, array $presentationDecisions = []): ServiceSectionType
    {
        if ($item->section_type instanceof ServiceSectionType) {
            return $item->section_type;
        }

        $itemType = strtolower($item->type);

        if ($itemType === 'songs') {
            return $item->semanticSectionType();
        }

        if ($itemType === 'bibles') {
            return $item->semanticSectionType();
        }

        if ($itemType === 'presentations') {
            // Only explicit or strong evidence drives structural alignment type resolution.
            // Weak evidence (position only) resolves to OTHER to prevent silent reclassification.
            $decision = $presentationDecisions[$item->id] ?? null;

            if (is_array($decision) && $decision['evidence'] !== 'weak') {
                return $decision['resolved_type'];
            }

            return ServiceSectionType::OTHER;
        }

        if ($itemType !== 'custom') {
            return ServiceSectionType::OTHER;
        }

        return $item->semanticSectionType();
    }

    /**
     * Returns true for structural section types that the OoS is authoritative enough
     * to reclassify an audio-only OTHER segment into.
     */
    private function isOosReclassifiableType(ServiceSectionType $type): bool
    {
        return in_array($type, [
            ServiceSectionType::CHILDRENS_TALK,
            ServiceSectionType::BIBLE_READING,
            ServiceSectionType::PRAYER,
            ServiceSectionType::NOTICES,
            ServiceSectionType::WELCOME,
        ], true);
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     */
    private function remainingSectionsContainType(Collection $sections, int $startIndex, ServiceSectionType $type): bool
    {
        return $sections
            ->slice($startIndex)
            ->contains(fn (ServiceSection $section): bool => $section->section_type === $type);
    }

    /**
     * @param  Collection<int, ChurchServiceItem>  $items
     */
    private function remainingItemsContainType(Collection $items, int $startIndex, ServiceSectionType $type): bool
    {
        return $items
            ->slice($startIndex)
            ->contains(fn (ChurchServiceItem $item): bool => $this->resolvedItemType($item) === $type);
    }
}
