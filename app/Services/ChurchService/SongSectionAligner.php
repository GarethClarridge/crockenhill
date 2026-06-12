<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Support\ServiceSectionConfidence;
use App\Traits\ReadsSectionMetadata;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SongSectionAligner
{
    use ReadsSectionMetadata;

    public function __construct(
        private readonly SectionAlignmentBaselineRestorer $baselineRestorer,
    ) {}

    /**
     * Run the greedy song-matching algorithm against the given sections and items.
     *
     * Iterates OoS song items in position order and greedily assigns the highest-scoring
     * unmatched song section to each. Unconfirmed remainders are passed to the positional
     * inference pass. Returns the IDs of sections that received a confirmed match.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  EloquentCollection<int, ChurchServiceItem>  $items
     * @return array<int, int>
     */
    public function align(EloquentCollection $sections, EloquentCollection $items): array
    {
        $matchedSectionIds = [];
        $matchedItemIds = [];

        /** @var EloquentCollection<int, ChurchServiceItem> $songItems */
        $songItems = $items
            ->filter(fn (ChurchServiceItem $item): bool => $this->isSongItem($item))
            ->values();

        /** @var EloquentCollection<int, ServiceSection> $songSections */
        $songSections = $sections
            ->filter(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Song)
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
            $this->applyConfirmedSongMatch($bestSection, $item, $bestScore);
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

            $this->applyInferredSongMatch($section, $item);
        }
    }

    private function applyConfirmedSongMatch(ServiceSection $section, ChurchServiceItem $item, float $score): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baselineRestorer->baseAlignmentMetadata($section), [
            'matched_item_type' => $item->type,
            'matched_item_title' => $item->title,
            'song_match_score' => round($score, 3),
            'song_match_strategy' => 'normalized_title',
            'song_title_matched' => $item->title,
        ]);
        unset($metadata['oos_alignment']['mismatch_reason']);

        $reviewFlags = $this->baselineRestorer->clearOosReviewFlags($this->reviewFlags($metadata));
        $metadata['review_flags'] = $reviewFlags;

        if ($reviewFlags === []) {
            unset($metadata['review_reason']);
        }

        $metadata['song_id'] = $item->song_id;

        $section->church_service_item_id = $item->id;
        $section->matched_item_id = $item->id;
        $section->expected_item_id = null;
        $section->song_match_type = ServiceSectionSongMatchType::Confirmed;
        $section->needs_manual_review = $section->needs_manual_review || $this->hasBlockingReviewFlag($reviewFlags);
        // Apply the +0.25 item-match delta first, then floor at the high-confidence threshold.
        // This preserves the original two-step logic from OosAlignmentService: applyMatchedItem(+0.25)
        // set the bumped confidence, then max(bumped, scoreForLevel('high')) was applied on top.
        $section->confidence = ServiceSectionConfidence::clamp(max(
            ServiceSectionConfidence::increase(
                ServiceSectionConfidence::resolve($section->confidence, $metadata),
                0.25
            ),
            ServiceSectionConfidence::scoreForLevel('high')
        ));
        $section->title = $item->title;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }

    private function applyInferredSongMatch(ServiceSection $section, ChurchServiceItem $item): void
    {
        $metadata = $this->metadata($section);
        $metadata['oos_alignment'] = array_merge($this->baselineRestorer->baseAlignmentMetadata($section), [
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
        $section->matched_item_id = $item->id;
        $section->expected_item_id = null;
        $section->song_match_type = ServiceSectionSongMatchType::Inferred;
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
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }

    private function songMatchScore(ServiceSection $section, ChurchServiceItem $item): float
    {
        $itemCandidates = $this->songCandidatesFromItem($item);
        $sectionCandidates = $this->songCandidatesFromSection($section);

        if ($item->song_id !== null && in_array($item->song_id, $this->catalogEvidenceSongIds($section), true)) {
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
     * Song IDs the section has catalog-backed evidence for: the OoS-written song_id,
     * the transcript/OCR match from MatchSongsFromTranscript, and any additional
     * songs detected within the same section (back-to-back songs in one block).
     *
     * @return array<int, int>
     */
    private function catalogEvidenceSongIds(ServiceSection $section): array
    {
        $metadata = $this->metadata($section);
        $songIds = [];

        if (is_numeric($metadata['song_id'] ?? null)) {
            $songIds[] = (int) $metadata['song_id'];
        }

        $transcriptMatch = $metadata['transcript_song_match'] ?? null;

        if (is_array($transcriptMatch) && is_numeric($transcriptMatch['song_id'] ?? null)) {
            $songIds[] = (int) $transcriptMatch['song_id'];
        }

        $additionalMatches = $metadata['additional_song_matches'] ?? [];

        if (is_array($additionalMatches)) {
            foreach ($additionalMatches as $additionalMatch) {
                if (is_array($additionalMatch) && is_numeric($additionalMatch['song_id'] ?? null)) {
                    $songIds[] = (int) $additionalMatch['song_id'];
                }
            }
        }

        return array_values(array_unique($songIds));
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
            $metadata['song_title_hint'] ?? null,
            $metadata['oos_alignment']['song_title_matched'] ?? null,
            $metadata['oos_alignment']['matched_item_title'] ?? null,
            // Section-level canonical key: no current production writer, but retained
            // as an explicit fallback for cases where a section-level override might be
            // seeded directly (e.g. backfill, migration, or future manual-section editor).
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
     * Returns true if this item should participate in song alignment.
     *
     * Respects the promoted section_type column so explicitly typed items are handled
     * consistently with StructuralSectionAligner::resolvedItemType().
     */
    private function isSongItem(ChurchServiceItem $item): bool
    {
        return $item->semanticSectionType() === ServiceSectionType::Song;
    }
}
