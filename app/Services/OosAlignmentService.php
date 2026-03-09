<?php

declare(strict_types=1);

namespace App\Services;

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

            $beforeState = $sections->mapWithKeys(
                fn (ServiceSection $section): array => [$section->id => $this->alignmentState($section)]
            )->all();

            foreach ($sections as $section) {
                $this->prepareSectionForAlignment($section);
            }

            $matchedSongSectionIds = [];
            $structureMismatchCount = 0;

            $matchedSongSectionIds = $this->alignSongSections($sections, $items);
            $structureMismatchCount += $this->alignStructuralSections($sections, $items);

            $reviewTriggers = $this->reviewTriggers(
                $sections,
                $matchedSongSectionIds,
                $structureMismatchCount,
                $beforeState,
                $lateArrival
            );

            foreach ($sections as $section) {
                $this->persistConfidenceLevel($section);
                $section->save();
            }

            $this->syncChurchServiceReviewState($churchService, $reviewTriggers);

            return [
                'aligned' => true,
                'review_triggers' => array_values($reviewTriggers),
                'matched_song_sections' => count($matchedSongSectionIds),
                'unmatched_song_sections' => $this->unmatchedSongSections($sections, $matchedSongSectionIds)->count(),
                'structure_mismatches' => $structureMismatchCount,
                'low_confidence_sections' => $this->lowConfidenceSections($sections)->count(),
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

            if (! $bestSection instanceof ServiceSection || $bestScore < 0.85) {
                continue;
            }

            $matchedSectionIds[] = $bestSection->id;
            $matchedItemIds[] = $item->id;
            $this->applyMatchedItem($bestSection, $item, 0.25);

            $metadata = $this->metadata($bestSection);
            $metadata['song_id'] = $item->song_id;
            $metadata['oos_alignment'] = array_merge($metadata['oos_alignment'] ?? [], [
                'song_match_score' => round($bestScore, 3),
                'song_match_strategy' => 'normalized_title',
                'song_title_matched' => $item->title,
            ]);

            $bestSection->confidence = ServiceSectionConfidence::clamp(max(
                ServiceSectionConfidence::resolve($bestSection->confidence, $metadata),
                0.90
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
        /** @var Collection<int, ServiceSection> $structuralSections */
        $structuralSections = $sections
            ->filter(fn (ServiceSection $section): bool => $section->section_type !== ServiceSectionType::SONG)
            ->values();

        /** @var Collection<int, ChurchServiceItem> $structuralItems */
        $structuralItems = $items
            ->filter(fn (ChurchServiceItem $item): bool => $this->resolvedItemType($item) !== ServiceSectionType::SONG)
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

            $expectedType = $this->resolvedItemType($item);

            if ($section->section_type === $expectedType) {
                $this->applyMatchedItem($section, $item, 0.35);

                if ($expectedType === ServiceSectionType::BIBLE_READING) {
                    $metadata = $this->metadata($section);
                    $metadata['reading_reference'] = $item->title;
                    $section->metadata = $metadata;
                } elseif (($section->title === null || trim($section->title) === '') && $expectedType !== ServiceSectionType::SERMON) {
                    $section->title = $item->title;
                }

                $sectionIndex++;
                $itemIndex++;

                continue;
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

    private function applyMatchedItem(ServiceSection $section, ChurchServiceItem $item, float $confidenceDelta): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baseAlignmentMetadata($section), [
            'matched_item_id' => $item->id,
            'matched_item_type' => $item->type,
            'matched_item_title' => $item->title,
        ]);
        unset($metadata['oos_alignment']['mismatch_reason']);

        $reviewFlags = $this->reviewFlags($metadata);
        $reviewFlags = array_values(array_filter(
            $reviewFlags,
            static fn (string $flag): bool => ! in_array($flag, ['oos_structure_mismatch', 'unmatched_song_section', 'song_alignment_inferred'], true)
        ));

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
        $metadata['oos_alignment'] = array_merge($this->baseAlignmentMetadata($section), [
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
        $metadata['oos_alignment'] = array_merge($this->baseAlignmentMetadata($section), [
            'matched_item_id' => $item->id,
            'matched_item_type' => $item->type,
            'matched_item_title' => $item->title,
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
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, int>  $matchedSongSectionIds
     * @param  array<int, array<string, mixed>>  $beforeState
     * @return array<int, string>
     */
    private function reviewTriggers(
        EloquentCollection $sections,
        array $matchedSongSectionIds,
        int $structureMismatchCount,
        array $beforeState,
        bool $lateArrival
    ): array {
        $reviewTriggers = [];

        if ($this->hasAmbiguousSermon($sections)) {
            $reviewTriggers[] = 'ambiguous_sermon_detection';
        }

        $unmatchedSongSections = $this->unmatchedSongSections($sections, $matchedSongSectionIds);
        if ($unmatchedSongSections->isNotEmpty()) {
            foreach ($unmatchedSongSections as $section) {
                $metadata = $this->metadata($section);
                $reviewFlags = $this->reviewFlags($metadata);
                $reviewFlags[] = 'unmatched_song_section';
                $metadata['review_flags'] = array_values(array_unique($reviewFlags));
                if (! array_key_exists('review_reason', $metadata)) {
                    $metadata['review_reason'] = 'unmatched_song_section';
                }
                $section->needs_manual_review = true;
                $section->confidence = ServiceSectionConfidence::decrease(
                    ServiceSectionConfidence::resolve($section->confidence, $metadata),
                    0.10
                );
                $section->metadata = $metadata;
            }

            $reviewTriggers[] = 'unmatched_song_sections';
        }

        if ($structureMismatchCount > 0) {
            $reviewTriggers[] = 'oos_structure_mismatch';
        }

        if ($lateArrival && $beforeState !== $sections->mapWithKeys(
            fn (ServiceSection $section): array => [$section->id => $this->alignmentState($section)]
        )->all()) {
            $reviewTriggers[] = 'late_oos_alignment_changed';
        }

        $lowConfidenceSections = $this->lowConfidenceSections($sections);
        if ($sections->count() > 0 && ($lowConfidenceSections->count() / $sections->count()) > 0.20) {
            $reviewTriggers[] = 'too_many_low_confidence_sections';
        }

        return array_values(array_unique($reviewTriggers));
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, int>  $matchedSongSectionIds
     * @return Collection<int, ServiceSection>
     */
    private function unmatchedSongSections(EloquentCollection $sections, array $matchedSongSectionIds): Collection
    {
        return $sections
            ->filter(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::SONG)
            ->reject(fn (ServiceSection $section): bool => in_array($section->id, $matchedSongSectionIds, true))
            ->values();
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @return Collection<int, ServiceSection>
     */
    private function lowConfidenceSections(EloquentCollection $sections): Collection
    {
        return $sections
            ->filter(fn (ServiceSection $section): bool => ServiceSectionConfidence::resolve($section->confidence, $section->metadata) < 0.85)
            ->values();
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
     * @param  EloquentCollection<int, ServiceSection>  $sections
     */
    private function hasAmbiguousSermon(EloquentCollection $sections): bool
    {
        return $sections->contains(static function (ServiceSection $section): bool {
            $reason = $section->metadata['review_reason'] ?? null;

            return in_array($reason, ['secondary_sermon_candidate', 'no_high_confidence_sermon_candidate'], true);
        });
    }

    private function resolvedItemType(ChurchServiceItem $item): ServiceSectionType
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

    private function prepareSectionForAlignment(ServiceSection $section): void
    {
        $metadata = $this->metadata($section);
        $existingAlignment = is_array($metadata['oos_alignment'] ?? null) ? $metadata['oos_alignment'] : [];
        $legacyAligned = ($metadata['classification_mode'] ?? null) === 'openlp_aligned';

        $section->confidence = ServiceSectionConfidence::resolve(
            is_numeric($existingAlignment['base_confidence'] ?? null) ? (float) $existingAlignment['base_confidence'] : $section->confidence,
            $metadata
        );
        $section->needs_manual_review = (bool) ($existingAlignment['base_needs_manual_review'] ?? $section->needs_manual_review);
        $section->title = $legacyAligned
            ? null
            : (array_key_exists('base_title', $existingAlignment) ? $existingAlignment['base_title'] : $section->title);
        $section->church_service_item_id = $legacyAligned
            ? null
            : (array_key_exists('base_church_service_item_id', $existingAlignment) ? $existingAlignment['base_church_service_item_id'] : $section->church_service_item_id);

        unset($metadata['oos_alignment'], $metadata['song_id'], $metadata['reading_reference']);

        $reviewFlags = array_values(array_filter(
            $this->reviewFlags($metadata),
            static fn (string $flag): bool => ! in_array($flag, ['oos_structure_mismatch', 'unmatched_song_section', 'song_alignment_inferred'], true)
        ));

        if ($reviewFlags === []) {
            if (in_array($metadata['review_reason'] ?? null, ['oos_structure_mismatch', 'unmatched_song_section', 'song_alignment_inferred'], true)) {
                unset($metadata['review_reason']);
            }

            unset($metadata['review_flags']);
        } else {
            $metadata['review_flags'] = $reviewFlags;
        }

        $section->metadata = $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAlignmentMetadata(ServiceSection $section): array
    {
        $metadata = $this->metadata($section);
        $existing = is_array($metadata['oos_alignment'] ?? null) ? $metadata['oos_alignment'] : [];

        return [
            'base_confidence' => ServiceSectionConfidence::resolve(
                is_numeric($existing['base_confidence'] ?? null) ? (float) $existing['base_confidence'] : $section->confidence,
                $metadata
            ),
            'base_needs_manual_review' => (bool) ($existing['base_needs_manual_review'] ?? $section->needs_manual_review),
            'base_title' => array_key_exists('base_title', $existing) ? $existing['base_title'] : $section->title,
            'base_church_service_item_id' => array_key_exists('base_church_service_item_id', $existing)
                ? $existing['base_church_service_item_id']
                : $section->church_service_item_id,
        ];
    }

    private function persistConfidenceLevel(ServiceSection $section): void
    {
        $metadata = $this->metadata($section);
        $confidence = ServiceSectionConfidence::resolve($section->confidence, $metadata);

        $section->confidence = $confidence;
        $metadata['confidence_level'] = ServiceSectionConfidence::levelFor($confidence);
        $metadata['confidence_score'] = $confidence;
        $section->metadata = $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function alignmentState(ServiceSection $section): array
    {
        $metadata = $this->metadata($section);

        return [
            'church_service_item_id' => $section->church_service_item_id,
            'title' => $section->title,
            'confidence' => ServiceSectionConfidence::resolve($section->confidence, $metadata),
            'reading_reference' => $metadata['reading_reference'] ?? null,
            'song_id' => $metadata['song_id'] ?? null,
            'review_reason' => $metadata['review_reason'] ?? null,
        ];
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
     * @param  array<int, string>  $reviewTriggers
     */
    private function syncChurchServiceReviewState(ChurchService $churchService, array $reviewTriggers): void
    {
        $importMetadata = is_array($churchService->import_metadata) ? $churchService->import_metadata : [];

        if ($reviewTriggers === []) {
            unset($importMetadata['review_triggers']);
        } else {
            $importMetadata['review_triggers'] = array_values($reviewTriggers);
        }

        $churchService->forceFill([
            'needs_review' => $reviewTriggers !== [] || $this->hasImportReviewSignal($churchService, $importMetadata),
            'import_metadata' => $importMetadata,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function hasImportReviewSignal(ChurchService $churchService, array $importMetadata): bool
    {
        if ($churchService->source === 'manual') {
            return false;
        }

        $confidenceScore = $importMetadata['confidence_score'] ?? null;
        if (! is_numeric($confidenceScore)) {
            return false;
        }

        $reviewThreshold = (float) config('service-tracking.email_parsing.review_threshold', 0.75);
        $autoImportThreshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        $score = (float) $confidenceScore;

        return $score >= $reviewThreshold && $score < $autoImportThreshold;
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
