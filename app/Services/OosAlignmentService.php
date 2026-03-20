<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OosAlignmentService
{
    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
        private readonly ServiceSectionReviewTriggerEvaluator $reviewTriggerEvaluator,
        private readonly PresentationItemClassifier $presentationItemClassifier,
        private readonly ChurchServiceReviewSynchronizer $reviewSynchronizer,
        private readonly SectionAlignmentBaselineRestorer $baselineRestorer,
    ) {}

    /**
     * @return array{
     *     aligned: bool,
     *     review_triggers: array<int, string>,
     *     matched_song_sections: int,
     *     unmatched_song_sections: int,
     *     structure_mismatches: int,
     *     low_confidence_sections: int
     * }
     */
    public function alignForProcessingLog(
        MediaProcessingLog $processingLog,
        ?ChurchService $churchService = null,
        bool $lateArrival = false
    ): array {
        $freshLog = MediaProcessingLog::query()->find($processingLog->id);

        if (! $freshLog instanceof MediaProcessingLog) {
            return $this->emptyResult();
        }

        return DB::transaction(function () use ($freshLog, $churchService, $lateArrival): array {
            $churchService = $this->resolveChurchService($freshLog, $churchService);

            if (! $churchService instanceof ChurchService) {
                return $this->emptyResult();
            }

            /** @var EloquentCollection<int, ChurchServiceItem> $items */
            $items = $churchService->items()
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                return $this->emptyResult();
            }

            /** @var EloquentCollection<int, ServiceSection> $sections */
            $sections = ServiceSection::query()
                ->where('media_processing_log_id', $freshLog->id)
                ->orderBy('section_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($sections->isEmpty()) {
                return $this->emptyResult();
            }

            $beforeState = $this->reviewTriggerEvaluator->captureAlignmentState($sections);

            foreach ($sections as $section) {
                $this->baselineRestorer->prepare($section);
            }

            $matchedSongSectionIds = $this->alignSongSections($sections, $items);
            $structureMismatchCount = $this->alignStructuralSections($sections, $items);

            $reviewTriggers = $this->reviewTriggerEvaluator->evaluate(
                $sections,
                $matchedSongSectionIds,
                $structureMismatchCount,
                $beforeState,
                $lateArrival
            );

            foreach ($sections as $section) {
                $this->baselineRestorer->persistConfidenceLevel($section);
                $section->save();
            }

            $this->reviewSynchronizer->sync($churchService, $sections, $reviewTriggers);

            return [
                'aligned' => true,
                'review_triggers' => array_values($reviewTriggers),
                'matched_song_sections' => count($matchedSongSectionIds),
                'unmatched_song_sections' => $this->reviewTriggerEvaluator->unmatchedSongSectionCount($sections, $matchedSongSectionIds),
                'structure_mismatches' => $structureMismatchCount,
                'low_confidence_sections' => $this->reviewTriggerEvaluator->lowConfidenceSectionCount($sections),
            ];
        });
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  EloquentCollection<int, ChurchServiceItem>  $items
     * @return array<int, int>
     */
    private function alignSongSections(EloquentCollection $sections, EloquentCollection $items): array
    {
        $matchedSectionIds = [];
        $matchedItemIds = [];

        /** @var EloquentCollection<int, ChurchServiceItem> $songItems */
        $songItems = $items
            ->filter(fn (ChurchServiceItem $item): bool => $this->resolvedItemType($item) === ServiceSectionType::SONG)
            ->values();

        /** @var EloquentCollection<int, ServiceSection> $songSections */
        $songSections = $sections
            ->filter(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::SONG)
            ->values();

        foreach ($songItems as $item) {
            $bestSection = null;
            $bestScore = 0.0;

            foreach ($songSections as $section) {
                if (in_array($section->id, $matchedSectionIds, true)) {
                    continue;
                }

                $score = $this->songMatchScore($section, $item);

                if ($score <= $bestScore) {
                    continue;
                }

                $bestScore = $score;
                $bestSection = $section;
            }

            if (! $bestSection instanceof ServiceSection || $bestScore < ServiceSectionConfidence::HIGH_THRESHOLD) {
                continue;
            }

            $matchedSectionIds[] = $bestSection->id;
            $matchedItemIds[] = $item->id;
            $this->applyMatchedItem($bestSection, $item, 0.25);

            $metadata = $this->metadata($bestSection);
            $metadata['song_id'] = $item->song_id;
            $metadata['oos_alignment'] = array_merge($metadata['oos_alignment'] ?? [], [
                'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
                'song_match_score' => round($bestScore, 3),
                'song_match_strategy' => 'normalized_title',
                'song_title_matched' => $item->title,
            ]);

            $bestSection->confidence = ServiceSectionConfidence::clamp(max(
                ServiceSectionConfidence::resolve($bestSection->confidence, $metadata),
                ServiceSectionConfidence::scoreForLevel('high')
            ));
            $bestSection->title = $item->title;
            $bestSection->metadata = $metadata;
        }

        $this->inferRemainingSongSectionLabels($songSections, $songItems, $matchedSectionIds, $matchedItemIds);

        return $matchedSectionIds;
    }

    /**
     * Apply low-confidence OoS labels to titleless song sections that have no title evidence.
     *
     * These inferred labels improve reviewability, but they are not treated as strong
     * song matches for catalog-linking purposes.
     *
     * @param  EloquentCollection<int, ServiceSection>  $songSections
     * @param  EloquentCollection<int, ChurchServiceItem>  $songItems
     * @param  array<int, int>  $matchedSectionIds
     * @param  array<int, int>  $matchedItemIds
     */
    private function inferRemainingSongSectionLabels(
        EloquentCollection $songSections,
        EloquentCollection $songItems,
        array $matchedSectionIds,
        array $matchedItemIds
    ): void {
        /** @var EloquentCollection<int, ServiceSection> $remainingSections */
        $remainingSections = $songSections
            ->reject(fn (ServiceSection $section): bool => in_array($section->id, $matchedSectionIds, true))
            ->values();

        /** @var EloquentCollection<int, ChurchServiceItem> $remainingItems */
        $remainingItems = $songItems
            ->reject(fn (ChurchServiceItem $item): bool => in_array($item->id, $matchedItemIds, true))
            ->values();

        if ($remainingSections->isEmpty() || $remainingItems->isEmpty()) {
            return;
        }

        if ($remainingSections->contains(fn (ServiceSection $section): bool => $this->songCandidatesFromSection($section) !== [])) {
            return;
        }

        $canonicalItemTitles = $remainingItems
            ->map(fn (ChurchServiceItem $item): ?string => $this->primarySongCandidateFromItem($item))
            ->filter(fn (?string $candidate): bool => is_string($candidate) && $candidate !== '')
            ->values();

        if ($canonicalItemTitles->count() !== $remainingItems->count() || $canonicalItemTitles->unique()->count() !== $canonicalItemTitles->count()) {
            return;
        }

        $pairCount = min($remainingSections->count(), $remainingItems->count());

        for ($index = 0; $index < $pairCount; $index++) {
            /** @var ServiceSection|null $section */
            $section = $remainingSections->get($index);
            /** @var ChurchServiceItem|null $item */
            $item = $remainingItems->get($index);

            if (! $section instanceof ServiceSection || ! $item instanceof ChurchServiceItem) {
                continue;
            }

            $this->applyInferredSongItem($section, $item);
        }
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  EloquentCollection<int, ChurchServiceItem>  $items
     */
    private function alignStructuralSections(EloquentCollection $sections, EloquentCollection $items): int
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
                    $section->metadata = $metadata;
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
                $section->metadata = $metadata;
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
                $section->metadata = $metadata;
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

        $section->metadata = $metadata;
    }

    private function applyMatchedItem(ServiceSection $section, ChurchServiceItem $item, float $confidenceDelta): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baselineRestorer->baseAlignmentMetadata($section), [
            'matched_item_id' => $item->id,
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
        $section->needs_manual_review = $section->needs_manual_review || $this->hasBlockingReviewFlag($reviewFlags);
        $section->confidence = ServiceSectionConfidence::clamp(
            ServiceSectionConfidence::increase(
                ServiceSectionConfidence::resolve($section->confidence, $metadata),
                $confidenceDelta
            )
        );
        $section->metadata = $metadata;
    }

    private function markMismatch(ServiceSection $section, ?ChurchServiceItem $item, string $reason): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baselineRestorer->baseAlignmentMetadata($section), [
            'mismatch_reason' => $reason,
            'expected_item_id' => $item?->id,
            'expected_item_title' => $item?->title,
            'expected_section_type' => $item instanceof ChurchServiceItem ? $this->resolvedItemType($item)->value : null,
        ]);

        $reviewFlags = $this->reviewFlags($metadata);
        $reviewFlags[] = 'oos_structure_mismatch';
        $metadata['review_flags'] = array_values(array_unique($reviewFlags));
        $metadata['review_reason'] = 'oos_structure_mismatch';

        $section->needs_manual_review = true;
        $section->confidence = ServiceSectionConfidence::decrease(
            ServiceSectionConfidence::resolve($section->confidence, $metadata),
            0.20
        );
        $section->metadata = $metadata;
    }

    private function applyInferredSongItem(ServiceSection $section, ChurchServiceItem $item): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baselineRestorer->baseAlignmentMetadata($section), [
            'matched_item_id' => $item->id,
            'matched_item_type' => $item->type,
            'matched_item_title' => $item->title,
            'song_match_type' => ServiceSectionSongMatchType::INFERRED->value,
            'song_match_strategy' => 'oos_order_inference',
            'song_title_matched' => $item->title,
        ]);

        $reviewFlags = $this->reviewFlags($metadata);
        $reviewFlags[] = 'song_alignment_inferred';
        $metadata['review_flags'] = array_values(array_unique($reviewFlags));
        $metadata['review_reason'] = 'song_alignment_inferred';

        $section->church_service_item_id = $item->id;
        $section->title = $item->title;
        $section->needs_manual_review = true;
        $section->confidence = ServiceSectionConfidence::clamp(min(
            max(
                ServiceSectionConfidence::increase(
                    ServiceSectionConfidence::resolve($section->confidence, $metadata),
                    0.05
                ),
                0.70
            ),
            0.84
        ));
        $section->metadata = $metadata;
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

    /**
     * @param  array<int, array{
     *     resolved_type: ServiceSectionType,
     *     suspected_type: ServiceSectionType|null,
     *     evidence: 'explicit'|'strong'|'weak',
     *     requires_review: bool,
     *     review_flag: string|null,
     *     reason: string
     * }>  $presentationDecisions  Pre-computed decision payload map of item ID → decision
     */
    private function resolvedItemType(ChurchServiceItem $item, array $presentationDecisions = []): ServiceSectionType
    {
        $metadataSectionType = $item->metadata['section_type'] ?? null;

        if (is_string($metadataSectionType)) {
            $resolved = ServiceSectionType::tryFrom($metadataSectionType);

            if ($resolved instanceof ServiceSectionType) {
                return $resolved;
            }
        }

        $itemType = strtolower($item->type);

        if ($itemType === 'songs') {
            return ServiceSectionType::SONG;
        }

        if ($itemType === 'bibles') {
            return ServiceSectionType::BIBLE_READING;
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

        return $this->inferCustomItemType($item->title);
    }

    private function inferCustomItemType(string $title): ServiceSectionType
    {
        $normalizedTitle = strtolower(trim($title));

        return match (true) {
            preg_match('/\b(children|children\'s)\b/', $normalizedTitle) === 1 => ServiceSectionType::CHILDRENS_TALK,
            preg_match('/\bprayer(s)?\b/', $normalizedTitle) === 1 => ServiceSectionType::PRAYER,
            preg_match('/\b(notices?|announcements?)\b/', $normalizedTitle) === 1 => ServiceSectionType::NOTICES,
            preg_match('/\bwelcome\b/', $normalizedTitle) === 1 => ServiceSectionType::WELCOME,
            preg_match('/\b(sermon|message)\b/', $normalizedTitle) === 1 => ServiceSectionType::SERMON,
            default => ServiceSectionType::OTHER,
        };
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

    private function songMatchScore(ServiceSection $section, ChurchServiceItem $item): float
    {
        $itemCandidates = $this->songCandidatesFromItem($item);
        $sectionCandidates = $this->songCandidatesFromSection($section);

        if ($item->song_id !== null && ($section->metadata['song_id'] ?? null) === $item->song_id) {
            return 1.0;
        }

        if ($itemCandidates === [] || $sectionCandidates === []) {
            return 0.0;
        }

        $best = 0.0;

        foreach ($itemCandidates as $itemCandidate) {
            foreach ($sectionCandidates as $sectionCandidate) {
                if ($itemCandidate === $sectionCandidate) {
                    return 1.0;
                }

                similar_text($itemCandidate, $sectionCandidate, $similarity);
                $best = max($best, round($similarity / 100, 3));
            }
        }

        return $best;
    }

    /**
     * @return array<int, string>
     */
    private function songCandidatesFromItem(ChurchServiceItem $item): array
    {
        $candidates = [];

        foreach ([$item->openlp_search_title, $item->source_title, $item->title] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $candidates[] = Song::canonicalizeKey($candidate);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function primarySongCandidateFromItem(ChurchServiceItem $item): ?string
    {
        return $this->songCandidatesFromItem($item)[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private function songCandidatesFromSection(ServiceSection $section): array
    {
        $metadata = $this->metadata($section);
        $candidates = [];

        foreach ([
            $section->title,
            $metadata['oos_alignment']['song_title_matched'] ?? null,
            $metadata['oos_alignment']['matched_item_title'] ?? null,
            // Section-level canonical key: no current production writer, but retained
            // as an explicit fallback for cases where a section-level override might be
            // seeded directly (e.g. backfill, migration, or future manual-section editor).
            // The item-level equivalent is written by ManageChurchService to
            // church_service_items.metadata and is read by ChurchServiceSongLinker.
            $metadata['linked_song_canonical_key'] ?? null,
        ] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $candidates[] = Song::canonicalizeKey($candidate);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(ServiceSection $section): array
    {
        return is_array($section->metadata) ? $section->metadata : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    private function reviewFlags(array $metadata): array
    {
        $flags = $metadata['review_flags'] ?? [];

        if (! is_array($flags)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $flag): ?string => is_string($flag) ? $flag : null, $flags)
        ));
    }

    /**
     * @param  array<int, string>  $reviewFlags
     */
    private function hasBlockingReviewFlag(array $reviewFlags): bool
    {
        return $reviewFlags !== [];
    }

    private function resolveChurchService(MediaProcessingLog $processingLog, ?ChurchService $churchService): ?ChurchService
    {
        if ($churchService instanceof ChurchService) {
            if ($processingLog->church_service_id !== $churchService->id) {
                $processingLog->forceFill([
                    'church_service_id' => $churchService->id,
                ])->saveQuietly();
            }

            return $churchService->fresh();
        }

        if ($processingLog->churchService instanceof ChurchService) {
            return $processingLog->churchService;
        }

        if ($processingLog->church_service_id !== null) {
            return $processingLog->churchService()->first();
        }

        $identity = $this->identityResolver->resolve($processingLog);

        if ($identity === null) {
            return null;
        }

        $resolved = ChurchService::query()
            ->where('date', $identity['date'])
            ->where('service', $identity['service']->value)
            ->first();

        if ($resolved instanceof ChurchService) {
            $processingLog->forceFill([
                'church_service_id' => $resolved->id,
            ])->saveQuietly();
        }

        return $resolved;
    }

    /**
     * @return array{
     *     aligned: bool,
     *     review_triggers: array<int, string>,
     *     matched_song_sections: int,
     *     unmatched_song_sections: int,
     *     structure_mismatches: int,
     *     low_confidence_sections: int
     * }
     */
    private function emptyResult(): array
    {
        return [
            'aligned' => false,
            'review_triggers' => [],
            'matched_song_sections' => 0,
            'unmatched_song_sections' => 0,
            'structure_mismatches' => 0,
            'low_confidence_sections' => 0,
        ];
    }
}
