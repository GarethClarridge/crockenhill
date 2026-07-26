<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Support\ServiceSectionConfidence;

class StructureMergePolicy
{
    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureResolver,
    ) {}

    /**
     * Determine whether an incoming source import should go through merge planning
     * rather than direct sync when a matching service already exists.
     *
     * Merge planning only applies when the existing service was projected from
     * livestream and the incoming source is a different, higher-authority source.
     */
    public function requiresMergePlanning(
        ChurchService $churchService,
        ChurchServiceItemSource $incomingSource,
    ): bool {
        if ($incomingSource === ChurchServiceItemSource::Livestream) {
            return false;
        }

        return $this->hasHighConfidenceLivestreamItems($churchService);
    }

    /**
     * Classify whether a specific item-level difference is safe to auto-merge
     * or requires human review.
     *
     * When $identityConfirmed is true the caller has already established that the
     * incoming item is the same logical item as the existing one (via stable identity
     * matching). In that case a reorder alone is never a material conflict.
     *
     * @param  array<string, mixed>  $existingSnapshot
     * @param  array<string, mixed>  $incomingItem
     */
    public function isAutoMergeSafe(
        array $existingSnapshot,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource,
        bool $identityConfirmed = false,
    ): bool {
        $existingConfidence = $this->itemConfidenceLevel($existingSnapshot);

        if ($existingConfidence !== 'high') {
            return true;
        }

        if ($identityConfirmed) {
            return true;
        }

        if ($this->isEnrichmentOnly($existingSnapshot, $incomingItem)) {
            return true;
        }

        if ($this->isSongIdentificationMatch($existingSnapshot, $incomingItem)) {
            return true;
        }

        return false;
    }

    /**
     * Compare two full item lists and return the indices of items that require review.
     *
     * Matching uses a three-tier strategy to avoid false conflicts on item reordering:
     *  1. Stable identity — song title / openlp_search_title / source_title match, or
     *     non-song section_type + normalised title match.
     *  2. Position — same type at the same ordinal position.
     *  3. Type+section_type — last-resort fallback, only for unclaimed snapshots.
     *
     * Each existing snapshot can only be claimed once (tracked via $matchedIndices).
     *
     * @param  array<int, array<string, mixed>>  $existingSnapshots
     * @param  array<int, array<string, mixed>>  $incomingItems
     * @return array{auto_merge: list<int>, review_required: list<int>, unmatched_incoming: list<int>}
     */
    public function classifyIncomingItems(
        array $existingSnapshots,
        array $incomingItems,
        ChurchServiceItemSource $incomingSource,
    ): array {
        $autoMerge = [];
        $reviewRequired = [];
        $unmatchedIncoming = [];

        /** @var array<int, true> $matchedIndices Existing snapshot indices already claimed */
        $matchedIndices = [];

        $existingByPosition = [];
        foreach ($existingSnapshots as $existingIndex => $snapshot) {
            $position = $snapshot['position'] ?? null;
            if (is_int($position)) {
                $existingByPosition[$position] = ['index' => $existingIndex, 'snapshot' => $snapshot];
            }
        }

        foreach ($incomingItems as $index => $incomingItem) {
            $identityConfirmed = false;

            // Tier 1: stable identity match
            $matchingExisting = $this->findStableIdentityMatch($existingSnapshots, $incomingItem, $matchedIndices);

            if ($matchingExisting !== null) {
                $matchedIndices[$matchingExisting['_existing_index']] = true;
                $identityConfirmed = true;
            }

            // Tier 2: position match (only unclaimed snapshots)
            if ($matchingExisting === null) {
                $position = $incomingItem['position'] ?? ($index + 1);
                $candidate = $existingByPosition[$position] ?? null;

                if ($candidate !== null && ! isset($matchedIndices[$candidate['index']])) {
                    $matchingExisting = $candidate['snapshot'];
                    $matchedIndices[$candidate['index']] = true;
                }
            }

            // Tier 3: type+section_type fallback (only unclaimed snapshots)
            if ($matchingExisting === null) {
                $matchingExisting = $this->findTypeMatch($existingSnapshots, $incomingItem, $matchedIndices);

                if ($matchingExisting !== null) {
                    $matchedIndices[$matchingExisting['_existing_index']] = true;
                }
            }

            if ($matchingExisting === null) {
                $unmatchedIncoming[] = $index;

                continue;
            }

            // Strip the internal tracking key before passing to safety check helpers.
            unset($matchingExisting['_existing_index']);

            if ($this->isAutoMergeSafe($matchingExisting, $incomingItem, $incomingSource, $identityConfirmed)) {
                $autoMerge[] = $index;
            } else {
                $reviewRequired[] = $index;
            }
        }

        return [
            'auto_merge' => $autoMerge,
            'review_required' => $reviewRequired,
            'unmatched_incoming' => $unmatchedIncoming,
        ];
    }

    /**
     * Whether the service carries high-confidence detected evidence.
     *
     * Keyed on the link to a service section, not on `source`. An item the order
     * of service authored keeps its own provenance when a run matches it, so
     * asking "who wrote this row" would miss exactly the rows where both a plan
     * and a detected observation exist — the ones most in need of merge planning.
     */
    private function hasHighConfidenceLivestreamItems(ChurchService $churchService): bool
    {
        return $churchService->items()
            ->whereNotNull('livestream_service_section_id')
            ->with('livestreamServiceSection:id,confidence')
            ->get()
            ->contains(fn (ChurchServiceItem $item): bool => $this->itemConfidenceLevel($this->itemToSnapshot($item)) === 'high');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function itemConfidenceLevel(array $snapshot): string
    {
        $confidence = is_numeric($snapshot['confidence'] ?? null) ? (float) $snapshot['confidence'] : 0.0;

        return ServiceSectionConfidence::levelFor($confidence);
    }

    /**
     * An incoming item is enrichment-only when it fills blanks or improves metadata
     * without changing the semantic meaning of the existing item.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function isEnrichmentOnly(array $existing, array $incoming): bool
    {
        $existingTitle = $existing['title'] ?? '';
        $incomingTitle = $incoming['title'] ?? '';
        $existingSongId = $existing['song_id'] ?? null;
        $incomingSongId = $incoming['song_id'] ?? null;

        if ($existingTitle === '' && $incomingTitle !== '') {
            return true;
        }

        if ($existingSongId === null && $incomingSongId !== null) {
            $existingType = $existing['type'] ?? '';
            $incomingType = $incoming['type'] ?? '';
            if ($existingType === $incomingType) {
                return true;
            }
        }

        $existingSourceTitle = $existing['source_title'] ?? null;
        $incomingSourceTitle = $incoming['source_title'] ?? null;
        if ($existingSourceTitle === null && $incomingSourceTitle !== null) {
            return $this->titlesSemanticallyMatch($existingTitle, $incomingTitle);
        }

        return false;
    }

    /**
     * When both sides identify the same song, the merge is safe regardless of
     * minor metadata differences.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function isSongIdentificationMatch(array $existing, array $incoming): bool
    {
        $existingType = $existing['type'] ?? '';
        $incomingType = $incoming['type'] ?? '';

        if ($existingType !== 'songs' || $incomingType !== 'songs') {
            return false;
        }

        $existingSongId = $existing['song_id'] ?? null;
        $incomingSongId = $incoming['song_id'] ?? null;

        if (is_int($existingSongId) && $existingSongId === $incomingSongId) {
            return true;
        }

        return $this->titlesSemanticallyMatch(
            (string) ($existing['title'] ?? ''),
            (string) ($incoming['title'] ?? ''),
        );
    }

    /**
     * Whether two readings name the same passage, on whichever field carries a
     * parseable reference.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function referencesAgree(array $existing, array $incoming): bool
    {
        $reference = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return $this->scriptureResolver->anyReferencesAgree(
            [$reference($existing['title'] ?? null), $reference($existing['source_title'] ?? null)],
            [$reference($incoming['title'] ?? null), $reference($incoming['source_title'] ?? null)],
        );
    }

    private function titlesSemanticallyMatch(string $existingTitle, string $incomingTitle): bool
    {
        if ($existingTitle === '' || $incomingTitle === '') {
            return false;
        }

        $normalised = fn (string $value): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));

        return $normalised($existingTitle) === $normalised($incomingTitle);
    }

    /**
     * Tier 1: find an existing snapshot that matches the incoming item by stable identity.
     *
     * For songs: matches by openlp_search_title, source_title, or normalised title.
     * For non-songs: matches by section_type + normalised title.
     *
     * Returns the snapshot with a temporary '_existing_index' key so the caller can
     * mark it as claimed. Returns null when no unclaimed identity match exists.
     *
     * @param  array<int, array<string, mixed>>  $existingSnapshots
     * @param  array<string, mixed>  $incomingItem
     * @param  array<int, true>  $alreadyMatchedIndices
     * @return array<string, mixed>|null
     */
    private function findStableIdentityMatch(
        array $existingSnapshots,
        array $incomingItem,
        array $alreadyMatchedIndices,
    ): ?array {
        $incomingType = $incomingItem['type'] ?? null;

        foreach ($existingSnapshots as $existingIndex => $snapshot) {
            if (isset($alreadyMatchedIndices[$existingIndex])) {
                continue;
            }

            if (($snapshot['type'] ?? null) !== $incomingType) {
                continue;
            }

            $matched = false;

            if ($incomingType === 'songs') {
                $incomingSearchTitle = $incomingItem['openlp_search_title'] ?? null;
                $existingSearchTitle = $snapshot['openlp_search_title'] ?? null;

                if (is_string($incomingSearchTitle) && $incomingSearchTitle !== '' && $incomingSearchTitle === $existingSearchTitle) {
                    $matched = true;
                }

                if (! $matched) {
                    $incomingSourceTitle = $incomingItem['source_title'] ?? null;
                    $existingSourceTitle = $snapshot['source_title'] ?? null;

                    if (is_string($incomingSourceTitle) && $incomingSourceTitle !== '' && $incomingSourceTitle === $existingSourceTitle) {
                        $matched = true;
                    }
                }

                if (! $matched) {
                    $matched = $this->isSongIdentificationMatch($snapshot, $incomingItem);
                }
            } else {
                $incomingSectionType = $incomingItem['section_type'] ?? null;
                $existingSectionType = $snapshot['section_type'] ?? null;

                if ($incomingSectionType !== null && $incomingSectionType === $existingSectionType) {
                    $incomingTitle = (string) ($incomingItem['title'] ?? '');
                    $existingTitle = (string) ($snapshot['title'] ?? '');

                    if ($incomingTitle !== '' && $this->titlesSemanticallyMatch($existingTitle, $incomingTitle)) {
                        $matched = true;
                    }
                }

                // Keyed on type rather than section_type: an email plan often omits
                // the semantic type, and a plan's "Joshua 1" against a run's "Joshua
                // 1:1-9" is one reading either way.
                if (! $matched && $incomingType === 'bibles') {
                    $matched = $this->referencesAgree($snapshot, $incomingItem);
                }
            }

            if ($matched) {
                return array_merge($snapshot, ['_existing_index' => $existingIndex]);
            }
        }

        return null;
    }

    /**
     * Tier 3: find an existing snapshot matching by type + section_type, skipping already-claimed indices.
     *
     * @param  array<int, array<string, mixed>>  $existingSnapshots
     * @param  array<string, mixed>  $incomingItem
     * @param  array<int, true>  $alreadyMatchedIndices
     * @return array<string, mixed>|null
     */
    private function findTypeMatch(
        array $existingSnapshots,
        array $incomingItem,
        array $alreadyMatchedIndices,
    ): ?array {
        $incomingType = $incomingItem['type'] ?? null;
        $incomingSectionType = $incomingItem['section_type'] ?? null;

        foreach ($existingSnapshots as $existingIndex => $snapshot) {
            if (isset($alreadyMatchedIndices[$existingIndex])) {
                continue;
            }

            if (($snapshot['type'] ?? null) === $incomingType && ($snapshot['section_type'] ?? null) === $incomingSectionType) {
                return array_merge($snapshot, ['_existing_index' => $existingIndex]);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemToSnapshot(ChurchServiceItem $item): array
    {
        $confidence = $item->livestreamServiceSection instanceof ServiceSection
            ? $item->livestreamServiceSection->confidence
            : null;

        return [
            'id' => $item->id,
            'position' => $item->position,
            'type' => $item->type,
            'section_type' => $item->semanticSectionType()->value,
            'source' => $item->source?->value,
            'title' => $item->title,
            'source_title' => $item->source_title,
            'openlp_search_title' => $item->openlp_search_title,
            'song_id' => $item->song_id,
            'confidence' => $confidence,
            'metadata' => $item->metadata,
        ];
    }
}
