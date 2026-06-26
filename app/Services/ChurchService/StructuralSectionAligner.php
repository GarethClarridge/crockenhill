<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

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

    /**
     * Structural types a generic presentation slide can plausibly stand in for. The
     * positional fallback only links a stranded slide to a section of one of these types.
     *
     * @var array<int, ServiceSectionType>
     */
    private const POSITIONAL_FALLBACK_TYPES = [
        ServiceSectionType::ChildrensTalk,
        ServiceSectionType::Notices,
        ServiceSectionType::BibleReading,
    ];

    public function __construct(
        private readonly PresentationItemClassifier $presentationItemClassifier,
        private readonly SectionAlignmentBaselineRestorer $baselineRestorer,
        private readonly SectionItemAlignmentScorer $scorer,
        private readonly MediaInterludeCueDetector $mediaCueDetector,
    ) {}

    /**
     * Align structural sections against OoS items using a global optimal alignment
     * (Needleman–Wunsch) and apply the per-pair side-effects.
     *
     * Unlike the previous greedy two-pointer walk, sections that are legitimately absent
     * from a sparse slide-deck OoS (e.g. unlisted prayers) become free section gaps rather
     * than a desync cascade of `unexpected_detected_section` flags. Only genuine aligned type
     * conflicts count towards the returned structural mismatch total (used for review-trigger
     * evaluation). Songs and sermons are excluded from the walk, and tagged transition
     * microsections are skipped entirely.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  EloquentCollection<int, ChurchServiceItem>  $items
     */
    public function align(EloquentCollection $sections, EloquentCollection $items): int
    {
        $presentationClassification = $this->presentationItemClassifier->classify($items);
        $presentationDecisions = $presentationClassification['decisions'];
        $ambiguousChildrensTalk = $presentationClassification['childrens_talk_count'] > 1;

        $mediaInterludesEnabled = (bool) config('media-processing.media_interludes.enabled', true);

        // A sermon never has its own OoS line, so a detected sermon section can never align to
        // an item; excluding it (like songs) keeps it out of the conflict/penalty machinery so a
        // confidently detected sermon flows straight to extraction. Children's talks DO align to
        // presentation items, so they stay in the walk and are exempted at markMismatch() instead.
        // Tagged transition microsections are pure structural glue and are skipped outright.
        $sectionTypesExcludedFromWalk = [
            ServiceSectionType::Song,
            ServiceSectionType::Sermon,
        ];

        /** @var Collection<int, ServiceSection> $structuralSections */
        $structuralSections = $sections
            ->filter(fn (ServiceSection $section): bool => ! in_array($section->section_type, $sectionTypesExcludedFromWalk, true)
                && ! $this->isTransitionSection($section))
            ->values();

        /** @var Collection<int, ChurchServiceItem> $structuralItems */
        $structuralItems = $items
            ->filter(fn (ChurchServiceItem $item): bool => $this->resolvedItemType($item, $presentationDecisions) !== ServiceSectionType::Song)
            ->values();

        $pairs = $this->optimalAlignment($structuralSections, $structuralItems, $presentationDecisions, $mediaInterludesEnabled);

        $mismatchCount = 0;

        /** @var list<ChurchServiceItem> $itemGaps */
        $itemGaps = [];
        /** @var list<ServiceSection> $sectionGaps */
        $sectionGaps = [];

        foreach ($pairs as $pair) {
            $mismatchCount += $this->applyPair($pair, $presentationDecisions, $ambiguousChildrensTalk);

            if ($pair['kind'] === 'item_gap' && $pair['item'] instanceof ChurchServiceItem) {
                $itemGaps[] = $pair['item'];
            } elseif ($pair['kind'] === 'section_gap' && $pair['section'] instanceof ServiceSection) {
                $sectionGaps[] = $pair['section'];
            }
        }

        $this->applyPositionalFallback($itemGaps, $sectionGaps, $presentationDecisions);

        return $mismatchCount;
    }

    /**
     * Additive traceability pass over the DP remainders.
     *
     * Presentation slides with generic names ("Andrew Talk.pptx", "epap.pptx") never
     * content-anchor, so when the surrounding matched pairs strand them they fall out as
     * `item_gap`. Their corresponding section (e.g. a children's talk detected purely from
     * audio) likewise falls out as `section_gap`. This pass links the two when — and only
     * when — the classifier resolved or suspected a concrete type for the item and there is
     * exactly one unaligned section of that compatible type.
     *
     * The link is deliberately low-trust: it sets the item ids and a flagged
     * `positional_fallback` marker for review tooling, but never changes `section_type`,
     * never boosts confidence, and never alters the structural-mismatch total. It can never
     * influence sermon extraction.
     *
     * @param  list<ChurchServiceItem>  $itemGaps
     * @param  list<ServiceSection>  $sectionGaps
     * @param  array<int, array{
     *     resolved_type: ServiceSectionType,
     *     suspected_type: ServiceSectionType|null,
     *     evidence: 'explicit'|'strong'|'weak',
     *     requires_review: bool,
     *     review_flag: string|null,
     *     reason: string
     * }>  $presentationDecisions
     */
    private function applyPositionalFallback(array $itemGaps, array $sectionGaps, array $presentationDecisions): void
    {
        foreach ($itemGaps as $item) {
            $decision = $presentationDecisions[$item->id] ?? null;
            if (! is_array($decision)) {
                continue;
            }

            $targetType = $this->positionalFallbackType($decision);
            if (! $targetType instanceof ServiceSectionType) {
                continue;
            }

            $candidates = array_values(array_filter(
                $sectionGaps,
                static fn (ServiceSection $section): bool => $section->section_type === $targetType
            ));

            // Conservatism is the whole safety story: a single unambiguous candidate only.
            if (count($candidates) !== 1) {
                continue;
            }

            $section = $candidates[0];
            $this->linkPositionalFallback($section, $item);

            // Consume the section so a later item cannot link to it a second time.
            $sectionGaps = array_values(array_filter(
                $sectionGaps,
                static fn (ServiceSection $candidate): bool => $candidate->id !== $section->id
            ));
        }
    }

    /**
     * The concrete section type a stranded presentation item points at, if any. Prefers an
     * explicit/strong resolved type, then the position-only suspected type; only the three
     * structural types a slide can plausibly stand in for qualify.
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
    private function positionalFallbackType(array $decision): ?ServiceSectionType
    {
        foreach ([$decision['resolved_type'], $decision['suspected_type']] as $type) {
            if ($type instanceof ServiceSectionType && in_array($type, self::POSITIONAL_FALLBACK_TYPES, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Record the low-trust positional link on an otherwise-unaligned section. Writes the
     * base_* snapshot so a rerun's baseline restore can undo it, sets the item ids and the
     * `positional_fallback` metadata marker, and raises the `presentation_positional_fallback`
     * review flag. It never touches section_type or confidence.
     */
    private function linkPositionalFallback(ServiceSection $section, ChurchServiceItem $item): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baselineRestorer->baseAlignmentMetadata($section), [
            'matched_item_type' => $item->type,
            'matched_item_title' => $item->title,
            'positional_fallback' => true,
        ]);

        $reviewFlags = $this->reviewFlags($metadata);
        $reviewFlags[] = 'presentation_positional_fallback';
        $metadata['review_flags'] = array_values(array_unique($reviewFlags));

        if (! array_key_exists('review_reason', $metadata)
            || in_array($metadata['review_reason'], SectionAlignmentBaselineRestorer::OOS_REVIEW_REASONS, true)) {
            $metadata['review_reason'] = 'presentation_positional_fallback';
        }

        $section->church_service_item_id = $item->id;
        $section->matched_item_id = $item->id;
        $section->expected_item_id = null;
        $section->needs_manual_review = true;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }

    /**
     * Compute the optimal monotonic alignment of sections to items, returning an ordered list
     * of pairing decisions to apply. Diagonal moves are matches/reclassifications/media-interludes
     * (or, as a last resort when no gap arrangement is as cheap, conflicts); the two gap moves
     * leave one side unpaired.
     *
     * @param  Collection<int, ServiceSection>  $sections
     * @param  Collection<int, ChurchServiceItem>  $items
     * @param  array<int, array{
     *     resolved_type: ServiceSectionType,
     *     suspected_type: ServiceSectionType|null,
     *     evidence: 'explicit'|'strong'|'weak',
     *     requires_review: bool,
     *     review_flag: string|null,
     *     reason: string
     * }>  $presentationDecisions
     * @return list<array{
     *     kind: 'match'|'reclassify'|'media'|'conflict'|'section_gap'|'item_gap',
     *     section: ServiceSection|null,
     *     item: ChurchServiceItem|null,
     *     resolved_type: ServiceSectionType|null
     * }>
     */
    private function optimalAlignment(
        Collection $sections,
        Collection $items,
        array $presentationDecisions,
        bool $mediaInterludesEnabled
    ): array {
        $n = $sections->count();
        $m = $items->count();

        $sectionGap = $this->scorer->sectionGapScore();
        $itemGap = $this->scorer->itemGapScore();

        // Precompute per-item resolution and per-section cue detection once.
        $resolvedTypes = [];
        $treatAsMedia = [];
        foreach ($items as $j => $item) {
            $resolvedTypes[$j] = $this->resolvedItemType($item, $presentationDecisions);
            $treatAsMedia[$j] = $mediaInterludesEnabled && $this->isMediaItem($item);
        }

        $hasCue = [];
        foreach ($sections as $i => $section) {
            $hasCue[$i] = $this->mediaCueDetector->hasCue($this->sectionTranscript($section));
        }

        /** @var array<int, array<int, float>> $dp */
        $dp = [];
        for ($i = 0; $i <= $n; $i++) {
            $dp[$i] = array_fill(0, $m + 1, 0.0);
        }
        for ($i = 1; $i <= $n; $i++) {
            $dp[$i][0] = $dp[$i - 1][0] + $sectionGap;
        }
        for ($j = 1; $j <= $m; $j++) {
            $dp[0][$j] = $dp[0][$j - 1] + $itemGap;
        }

        /** @var array<int, array<int, array{kind: string, score: float}>> $pairScores */
        $pairScores = [];
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                /** @var ServiceSection $section */
                $section = $sections->get($i - 1);
                /** @var ChurchServiceItem $item */
                $item = $items->get($j - 1);

                $pair = $this->scorer->scorePair($section, $item, $resolvedTypes[$j - 1], $treatAsMedia[$j - 1], $hasCue[$i - 1]);
                $pairScores[$i][$j] = $pair;

                $dp[$i][$j] = max(
                    $dp[$i - 1][$j - 1] + $pair['score'],
                    $dp[$i - 1][$j] + $sectionGap,
                    $dp[$i][$j - 1] + $itemGap,
                );
            }
        }

        return $this->traceback($sections, $items, $dp, $pairScores, $resolvedTypes, $sectionGap, $itemGap);
    }

    /**
     * Walk the filled DP matrix back to the origin, preferring real diagonal matches, then gaps,
     * then conflict diagonals last so a type conflict is only recorded when no equally-cheap gap
     * arrangement exists.
     *
     * @param  Collection<int, ServiceSection>  $sections
     * @param  Collection<int, ChurchServiceItem>  $items
     * @param  array<int, array<int, float>>  $dp
     * @param  array<int, array<int, array{kind: string, score: float}>>  $pairScores
     * @param  array<int, ServiceSectionType>  $resolvedTypes
     * @return list<array{
     *     kind: 'match'|'reclassify'|'media'|'conflict'|'section_gap'|'item_gap',
     *     section: ServiceSection|null,
     *     item: ChurchServiceItem|null,
     *     resolved_type: ServiceSectionType|null
     * }>
     */
    private function traceback(
        Collection $sections,
        Collection $items,
        array $dp,
        array $pairScores,
        array $resolvedTypes,
        float $sectionGap,
        float $itemGap
    ): array {
        $ops = [];
        $i = $sections->count();
        $j = $items->count();

        while ($i > 0 || $j > 0) {
            $pair = ($i > 0 && $j > 0) ? $pairScores[$i][$j] : null;
            $diagScore = $pair !== null ? $dp[$i - 1][$j - 1] + $pair['score'] : null;

            if ($pair !== null && $pair['kind'] !== 'conflict' && $diagScore !== null && $this->scoresEqual($dp[$i][$j], $diagScore)) {
                $ops[] = $this->diagonalOp($pair['kind'], $sections->get($i - 1), $items->get($j - 1), $resolvedTypes[$j - 1]);
                $i--;
                $j--;

                continue;
            }

            if ($i > 0 && $this->scoresEqual($dp[$i][$j], $dp[$i - 1][$j] + $sectionGap)) {
                $ops[] = ['kind' => 'section_gap', 'section' => $sections->get($i - 1), 'item' => null, 'resolved_type' => null];
                $i--;

                continue;
            }

            if ($j > 0 && $this->scoresEqual($dp[$i][$j], $dp[$i][$j - 1] + $itemGap)) {
                $ops[] = ['kind' => 'item_gap', 'section' => null, 'item' => $items->get($j - 1), 'resolved_type' => null];
                $j--;

                continue;
            }

            // Only a strictly-best conflict diagonal remains (CONFLICT_SCORE > both gaps summed).
            if ($pair !== null) {
                $ops[] = $this->diagonalOp($pair['kind'], $sections->get($i - 1), $items->get($j - 1), $resolvedTypes[$j - 1]);
                $i--;
                $j--;

                continue;
            }

            // Defensive: exhaust any remaining edge cell as a gap (unreachable in practice).
            // Reaching here means $pair is null, so exactly one of $i/$j is still positive.
            if ($i > 0) {
                $ops[] = ['kind' => 'section_gap', 'section' => $sections->get($i - 1), 'item' => null, 'resolved_type' => null];
                $i--;
            } else {
                $ops[] = ['kind' => 'item_gap', 'section' => null, 'item' => $items->get($j - 1), 'resolved_type' => null];
                $j--;
            }
        }

        return array_reverse($ops);
    }

    /**
     * @return array{
     *     kind: 'match'|'reclassify'|'media'|'conflict',
     *     section: ServiceSection|null,
     *     item: ChurchServiceItem|null,
     *     resolved_type: ServiceSectionType|null
     * }
     */
    private function diagonalOp(string $kind, ?ServiceSection $section, ?ChurchServiceItem $item, ServiceSectionType $resolvedType): array
    {
        /** @var 'match'|'reclassify'|'media'|'conflict' $kind */
        return ['kind' => $kind, 'section' => $section, 'item' => $item, 'resolved_type' => $resolvedType];
    }

    private function scoresEqual(float $a, float $b): bool
    {
        return abs($a - $b) < 1e-9;
    }

    /**
     * Apply the side-effects for a single pairing decision and return 1 if it recorded a
     * structural mismatch, 0 otherwise.
     *
     * @param  array{
     *     kind: 'match'|'reclassify'|'media'|'conflict'|'section_gap'|'item_gap',
     *     section: ServiceSection|null,
     *     item: ChurchServiceItem|null,
     *     resolved_type: ServiceSectionType|null
     * }  $pair
     * @param  array<int, array{
     *     resolved_type: ServiceSectionType,
     *     suspected_type: ServiceSectionType|null,
     *     evidence: 'explicit'|'strong'|'weak',
     *     requires_review: bool,
     *     review_flag: string|null,
     *     reason: string
     * }>  $presentationDecisions
     */
    private function applyPair(array $pair, array $presentationDecisions, bool $ambiguousChildrensTalk): int
    {
        // Gaps are free: a section absent from the OoS keeps its baseline, an unlisted item has
        // no detected counterpart. Neither counts as a structural mismatch.
        if ($pair['kind'] === 'section_gap' || $pair['kind'] === 'item_gap') {
            return 0;
        }

        $section = $pair['section'];
        $item = $pair['item'];

        if (! $section instanceof ServiceSection || ! $item instanceof ChurchServiceItem) {
            return 0;
        }

        $resolvedType = $pair['resolved_type'] ?? $this->resolvedItemType($item, $presentationDecisions);

        switch ($pair['kind']) {
            case 'match':
                $this->applyExactMatch($section, $item, $resolvedType, $presentationDecisions, $ambiguousChildrensTalk);

                return 0;

            case 'reclassify':
                $this->applyReclassifyMatch($section, $item, $resolvedType, $presentationDecisions, $ambiguousChildrensTalk);

                return 0;

            case 'media':
                $this->applyMediaInterlude($section, $item);

                return 0;

            case 'conflict':
            default:
                return $this->applyConflict($section, $item, $presentationDecisions);
        }
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
    private function applyExactMatch(
        ServiceSection $section,
        ChurchServiceItem $item,
        ServiceSectionType $resolvedType,
        array $presentationDecisions,
        bool $ambiguousChildrensTalk
    ): void {
        $this->applyMatchedItem($section, $item, 0.35);

        if ($resolvedType === ServiceSectionType::BibleReading) {
            $metadata = $this->metadata($section);
            $metadata['reading_reference'] = $item->title;
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        } elseif (($section->title === null || trim($section->title) === '') && $resolvedType !== ServiceSectionType::Sermon) {
            $section->title = $item->title;
        }

        $decision = $presentationDecisions[$item->id] ?? null;
        if (is_array($decision)) {
            $this->applyPresentationDecisionMetadata($section, $decision, $ambiguousChildrensTalk);
        }
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
    private function applyReclassifyMatch(
        ServiceSection $section,
        ChurchServiceItem $item,
        ServiceSectionType $resolvedType,
        array $presentationDecisions,
        bool $ambiguousChildrensTalk
    ): void {
        $section->section_type = $resolvedType;
        $this->applyMatchedItem($section, $item, 0.35);

        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($metadata['oos_alignment'] ?? [], [
            'reclassified_from' => ServiceSectionType::Other->value,
            'reclassified_by' => 'oos_alignment',
        ]);
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->title = $item->title;

        $decision = $presentationDecisions[$item->id] ?? null;
        if (is_array($decision)) {
            $this->applyPresentationDecisionMetadata($section, $decision, $ambiguousChildrensTalk);
        }
    }

    /**
     * Tag a detected block that aligned to an OoS media item (e.g. "Bibles.mp4") as a media
     * interlude. The block is normalised to OTHER plus a metadata flag (no new enum case),
     * matched to the item so it is not counted as a structural gap, and cleared of review state
     * because a played video is structural context — never extracted, never published.
     */
    private function applyMediaInterlude(ServiceSection $section, ChurchServiceItem $item): void
    {
        $section->section_type = ServiceSectionType::Other;
        $this->applyMatchedItem($section, $item, 0.35);

        $metadata = $this->metadata($section);
        $metadata['media_interlude'] = true;
        $metadata['media_title'] = $item->title;
        $metadata['oos_alignment'] = array_merge($metadata['oos_alignment'] ?? [], [
            'media_interlude' => true,
        ]);
        unset($metadata['review_flags'], $metadata['review_reason']);
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = false;
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
    private function applyConflict(ServiceSection $section, ChurchServiceItem $item, array $presentationDecisions): int
    {
        // Preserve the legacy weak-evidence presentation hint: when an item only weakly suggests
        // a type (position-only) and the section did not adopt it, record the suspicion for review
        // tooling without reclassifying or flagging.
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

        return $this->markMismatch($section, $item, 'oos_type_mismatch') ? 1 : 0;
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

            if ($ambiguousChildrensTalk && $decision['resolved_type'] === ServiceSectionType::ChildrensTalk) {
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
        } elseif ($ambiguousChildrensTalk && $decision['resolved_type'] === ServiceSectionType::ChildrensTalk) {
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

    /**
     * Record a structural mismatch on the section, returning true when a mismatch was
     * actually applied. Sermon and children's-talk sections are legitimately absent from
     * (or only loosely represented in) the OoS, so they are exempted: they keep their
     * confidence and review state instead of taking the oos_structure_mismatch flag and
     * the −0.20 penalty, and they do not count towards the structural mismatch total.
     */
    private function markMismatch(ServiceSection $section, ?ChurchServiceItem $item, string $reason): bool
    {
        if (in_array($section->section_type, [ServiceSectionType::Sermon, ServiceSectionType::ChildrensTalk], true)) {
            return false;
        }

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

        return true;
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

            return ServiceSectionType::Other;
        }

        if ($itemType !== 'custom') {
            return ServiceSectionType::Other;
        }

        return $item->semanticSectionType();
    }

    /**
     * An OoS media item (e.g. an OpenLP "media" plugin entry, or a title ending in a media
     * file extension). Detection is intentionally audio/OoS only — the projector video may not
     * be in the livestream feed, so visual analysis is never consulted.
     */
    private function isMediaItem(ChurchServiceItem $item): bool
    {
        if (strtolower($item->type) === 'media') {
            return true;
        }

        return preg_match('/\.(mp4|mov|avi|mkv|webm|mp3|wav|m4a)$/i', (string) $item->title) === 1;
    }

    private function isTransitionSection(ServiceSection $section): bool
    {
        return ($this->metadata($section)['is_transition'] ?? false) === true;
    }

    private function sectionTranscript(ServiceSection $section): string
    {
        $transcript = $this->metadata($section)['transcript'] ?? null;

        return is_string($transcript) ? $transcript : '';
    }
}
