<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\Scripture\ScriptureReferenceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChurchServiceItemSyncService
{
    /**
     * Minimum anchors needed before a detected run's ordering is trusted to place
     * items it never matched. Two is the floor for establishing any relative order.
     */
    private const int MinimumAnchorCount = 2;

    /**
     * Minimum share of the existing song list a detected run must match before its
     * ordering is treated as well evidenced. Songs carry slides, so an order of
     * service lists all of them — near-total coverage is a fair expectation.
     */
    private const float MinimumSongAnchorCoverage = 0.5;

    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureResolver,
        private readonly ServiceItemCatalogueSongResolver $catalogueSongResolver,
        private readonly ServiceItemMergeOrderResolver $orderResolver = new ServiceItemMergeOrderResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $incomingItems
     * @param  array{replace_mode?:bool,emit_merge_evidence?:bool}  $options
     * @return array{
     *     conflicts: array<int, array<string, mixed>>
     * }
     */
    public function sync(
        ChurchService $churchService,
        array $incomingItems,
        ChurchServiceItemSource|string $incomingSource = ChurchServiceItemSource::OpenLp,
        array $options = [],
    ): array {
        $incomingSource = $this->normaliseSource($incomingSource);
        $replaceMode = (bool) ($options['replace_mode'] ?? false);
        $emitMergeEvidence = (bool) ($options['emit_merge_evidence'] ?? true);

        $incomingItems = $this->collapseAdjacentBibleReadingWrappers($incomingItems);

        // Ahead of the lock: a caller that already classified this merge resolved the
        // same links, so this is usually a no-op, but a direct sync must not depend on
        // that.
        $incomingItems = $this->catalogueSongResolver->resolveAll($incomingItems, $incomingSource);

        return DB::transaction(function () use ($churchService, $incomingItems, $incomingSource, $replaceMode, $emitMergeEvidence): array {
            $lockedService = ChurchService::query()
                ->whereKey($churchService->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Collection<int, ChurchServiceItem> $existingItems */
            $existingItems = $lockedService->items()->withTrashed()->orderBy('id')->get();
            $matchedExistingItemIds = [];
            $seenPositions = [];
            $conflicts = [];
            /** @var list<array<string, mixed>> $plan */
            $plan = [];

            foreach ($incomingItems as $index => $rawIncomingItem) {
                $incomingItem = $this->normaliseIncomingItem($rawIncomingItem, $index + 1);

                if (in_array($incomingItem['position'], $seenPositions, true)) {
                    throw new RuntimeException('Incoming items contain duplicate active positions.');
                }

                $seenPositions[] = $incomingItem['position'];

                $stableMatch = $this->findStableMatch(
                    $existingItems,
                    $incomingItem,
                    $matchedExistingItemIds,
                    $incomingSource,
                );

                $positionFallback = $this->findPositionFallbackMatch(
                    $existingItems,
                    $incomingItem,
                    $matchedExistingItemIds,
                    $incomingSource,
                );

                $match = $stableMatch ?? $positionFallback;

                if ($match !== null) {
                    $existingSource = $this->sourceForExistingItem($match);
                    $preserveExistingIdentity = $this->shouldPreserveExistingIdentity($match, $incomingSource);
                    $matchConflicts = $this->conflictsForMatchedItem(
                        $match,
                        $incomingItem,
                        $incomingSource,
                        $preserveExistingIdentity,
                    );
                    $conflicts = [...$conflicts, ...$matchConflicts];
                    $plan[] = [
                        'kind' => 'update',
                        'existing_item' => $match,
                        'existing_position' => $match->position,
                        'incoming_item' => $incomingItem,
                        'raw_incoming_item' => $rawIncomingItem,
                        'desired_position' => $this->shouldKeepExistingPosition($existingSource, $incomingSource)
                            ? $match->position
                            : $incomingItem['position'],
                    ];
                    $matchedExistingItemIds[] = $match->id;

                    continue;
                }

                $plan[] = [
                    'kind' => 'create',
                    'existing_position' => null,
                    'normalized_item' => $incomingItem,
                    'raw_item' => $rawIncomingItem,
                    'desired_position' => $incomingItem['position'],
                ];
            }

            /** @var list<ChurchServiceItem> $preservedItems */
            $preservedItems = [];
            $itemsToDelete = [];

            foreach ($existingItems as $existingItem) {
                if (
                    ! in_array($existingItem->id, $matchedExistingItemIds, true)
                    && ! $existingItem->trashed()
                ) {
                    if ($this->shouldDeleteUnmatchedItem($existingItem, $incomingSource, $replaceMode)) {
                        $itemsToDelete[] = $existingItem;

                        continue;
                    }

                    $preservedItems[] = $existingItem;

                    if ($this->shouldFlagPreservedSongConflict($existingItem, $incomingSource)) {
                        $conflicts[] = [
                            'type' => 'preserved_existing_song',
                            'incoming_source' => $incomingSource->value,
                            'existing_item' => $this->snapshotItem($existingItem),
                        ];
                    }

                    if ($this->shouldFlagPreservedExistingItemConflict($existingItem, $incomingSource)) {
                        $conflicts[] = [
                            'type' => 'preserved_existing_item',
                            'incoming_source' => $incomingSource->value,
                            'existing_item' => $this->snapshotItem($existingItem),
                        ];
                    }
                }
            }

            foreach ($itemsToDelete as $itemToDelete) {
                $itemToDelete->delete();
            }

            usort($preservedItems, fn (ChurchServiceItem $left, ChurchServiceItem $right): int => $left->position <=> $right->position);

            $isCrossSourceMerge = $this->isCrossSourceMerge($plan, $preservedItems, $incomingSource);

            if ($emitMergeEvidence) {
                $conflicts = [
                    ...$conflicts,
                    ...$this->mergeEvidenceConflicts($plan, $preservedItems, $incomingSource, $isCrossSourceMerge),
                ];
            }

            if ($this->shouldAnchorOrder($isCrossSourceMerge, $preservedItems, $incomingSource)) {
                $this->applyAnchoredOrder($lockedService, $plan, $preservedItems, $incomingSource);
            } else {
                $this->applyIncomingPositions($lockedService, $plan, $incomingSource);
            }

            if ($this->hasDuplicateActivePositions($lockedService)) {
                $this->resequenceActiveItems($lockedService);
            }

            $this->assertUniqueActivePositions($lockedService);

            return [
                'conflicts' => $conflicts,
            ];
        });
    }

    /**
     * OpenLP can export a generic custom slide immediately before the Bible
     * plugin's detailed reference. Together they describe one reading.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function collapseAdjacentBibleReadingWrappers(array $items): array
    {
        $items = array_values($items);
        $collapsed = [];

        for ($index = 0; $index < count($items); $index++) {
            $item = $items[$index];
            $next = $items[$index + 1] ?? null;
            $title = $this->normaliseString($item['title'] ?? null);

            if (
                is_array($next)
                && is_string($title)
                && in_array(strtolower($title), ['bible reading', 'scripture reading'], true)
                && strtolower((string) ($next['type'] ?? '')) === 'bibles'
            ) {
                $nextMetadata = is_array($next['metadata'] ?? null) ? $next['metadata'] : [];
                $nextMetadata['additional_source_titles'] = [
                    ...($nextMetadata['additional_source_titles'] ?? []),
                    $title,
                    $this->normaliseString($item['source_title'] ?? null),
                ];
                $collapsed[] = [
                    ...$next,
                    'position' => $item['position'] ?? $index + 1,
                    'metadata' => $nextMetadata,
                ];
                $index++;

                continue;
            }

            $collapsed[] = $item;
        }

        return $collapsed;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}
     */
    private function normaliseIncomingItem(array $item, int $defaultPosition): array
    {
        $position = $item['position'] ?? $defaultPosition;
        $type = $this->normaliseString($item['type'] ?? null);
        $title = $this->normaliseString($item['title'] ?? null);

        if (! is_int($position) || $position < 1) {
            throw new RuntimeException('Incoming service items must include a positive integer position.');
        }

        if ($type === null) {
            throw new RuntimeException('Incoming service items must include a type.');
        }

        if ($title === null) {
            throw new RuntimeException('Incoming service items must include a title.');
        }

        $metadata = $item['metadata'] ?? null;

        if (! is_array($metadata) && $metadata !== null) {
            throw new RuntimeException('Incoming service item metadata must be an array or null.');
        }

        $songId = $item['song_id'] ?? null;

        if (! is_int($songId) && $songId !== null) {
            throw new RuntimeException('Incoming service item song_id must be an integer or null.');
        }

        $sectionType = $this->resolveSectionType($item, $type, $title, $metadata);
        $metadata = $this->metadataWithoutPromotedSectionType($metadata);

        return [
            'position' => $position,
            'type' => $type,
            'section_type' => $sectionType->value,
            'title' => $title,
            'source_title' => $this->normaliseString($item['source_title'] ?? null),
            'openlp_search_title' => $this->normaliseString($item['openlp_search_title'] ?? null),
            'song_id' => $songId,
            'metadata' => $metadata,
        ];
    }

    /**
     * The strongest available anchor between the incoming item and one existing row.
     *
     * Each tier is searched across every candidate before the next is tried. Walking
     * the tiers per row instead would let an older row's weak agreement — a shared
     * normalised title, or a broad reading that merely contains the incoming one —
     * claim an item whose exact counterpart sits further down the list.
     *
     * @param  Collection<int, ChurchServiceItem>  $existingItems
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @param  array<int, int>  $matchedExistingItemIds
     */
    private function findStableMatch(
        Collection $existingItems,
        array $incomingItem,
        array $matchedExistingItemIds,
        ChurchServiceItemSource $incomingSource
    ): ?ChurchServiceItem {
        $candidates = $existingItems->filter(
            fn (ChurchServiceItem $existingItem): bool => ! in_array($existingItem->id, $matchedExistingItemIds, true)
                && $this->typesCanDescribeTheSameItem($existingItem, $incomingItem)
        );

        if ($candidates->isEmpty()) {
            return null;
        }

        $tiers = [
            // Both sides resolved to the same catalogue song, so the titles need
            // not agree at all.
            fn (ChurchServiceItem $existingItem): bool => $incomingItem['song_id'] !== null
                && $existingItem->song_id === $incomingItem['song_id'],

            fn (ChurchServiceItem $existingItem): bool => $incomingItem['openlp_search_title'] !== null
                && $existingItem->openlp_search_title === $incomingItem['openlp_search_title'],

            fn (ChurchServiceItem $existingItem): bool => $incomingItem['source_title'] !== null
                && $existingItem->source_title === $incomingItem['source_title'],

            fn (ChurchServiceItem $existingItem): bool => $this->shouldUseCrossSourceTitleMatch($existingItem, $incomingSource)
                && $this->hasMatchingNormalisedTitle($existingItem, $incomingItem),

            fn (ChurchServiceItem $existingItem): bool => $this->shouldUseCrossSourceTitleMatch($existingItem, $incomingSource)
                && $this->hasAgreeingScriptureReference($existingItem, $incomingItem),

            fn (ChurchServiceItem $existingItem): bool => $this->shouldUseCrossSourceTitleMatch($existingItem, $incomingSource)
                && $this->hasMatchingAssetFingerprint($existingItem, $incomingItem),
        ];

        foreach ($tiers as $matchesTier) {
            /** @var ChurchServiceItem|null $match */
            $match = $candidates->first($matchesTier);

            if ($match instanceof ChurchServiceItem) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, ChurchServiceItem>  $existingItems
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @param  array<int, int>  $matchedExistingItemIds
     */
    private function findPositionFallbackMatch(
        Collection $existingItems,
        array $incomingItem,
        array $matchedExistingItemIds,
        ChurchServiceItemSource $incomingSource
    ): ?ChurchServiceItem {
        /** @var ChurchServiceItem|null $match */
        $match = $existingItems->first(function (ChurchServiceItem $existingItem) use ($incomingItem, $matchedExistingItemIds, $incomingSource): bool {
            if (in_array($existingItem->id, $matchedExistingItemIds, true)) {
                return false;
            }

            if (! $this->canUsePositionFallback($existingItem, $incomingSource)) {
                return false;
            }

            return $existingItem->type === $incomingItem['type']
                && $existingItem->position === $incomingItem['position'];
        });

        return $match;
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @param  array<string, mixed>|null  $rawIncomingItem
     */
    private function updateMatchedItem(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource,
        int $position,
        ?array $rawIncomingItem = null,
    ): void {
        if ($existingItem->trashed()) {
            $existingItem->restore();
        }

        $existingSource = $this->sourceForExistingItem($existingItem);
        $preserveExistingIdentity = $this->shouldPreserveExistingIdentity($existingItem, $incomingSource);
        $preserveExistingPresentationIdentity = $this->shouldPreserveExistingPresentationIdentity($existingItem, $incomingItem);

        $fillData = [
            'position' => $position,
            'type' => $preserveExistingPresentationIdentity ? $existingItem->type : $incomingItem['type'],
            'section_type' => $this->resolveMergedSectionType($existingItem, $incomingItem),
            'source' => $this->resolveSource($existingItem, $incomingSource, $preserveExistingIdentity)->value,
            'title' => $this->resolveTitle(
                $existingItem,
                $incomingItem,
                $preserveExistingIdentity || $preserveExistingPresentationIdentity,
            ),
            'source_title' => $this->resolveSourceTitle($existingItem, $incomingItem, $preserveExistingIdentity),
            'openlp_search_title' => $this->resolveOpenLpSearchTitle($existingItem, $incomingItem, $preserveExistingIdentity),
            'song_id' => $this->resolveSongId($existingItem, $incomingItem, $preserveExistingIdentity),
            'metadata' => $this->resolveMetadata($existingItem, $incomingItem, $existingSource, $incomingSource, $preserveExistingIdentity),
        ];

        if ($rawIncomingItem !== null) {
            if (isset($rawIncomingItem['livestream_processing_id'])) {
                $fillData['livestream_processing_id'] = $rawIncomingItem['livestream_processing_id'];
            }

            if (isset($rawIncomingItem['livestream_service_section_id'])) {
                $fillData['livestream_service_section_id'] = $rawIncomingItem['livestream_service_section_id'];
            }
        }

        $existingItem->fill($fillData);

        $existingItem->save();
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function shouldPreserveExistingPresentationIdentity(
        ChurchServiceItem $existingItem,
        array $incomingItem,
    ): bool {
        return $this->isVisualAssetType($incomingItem['type'])
            && ! $this->isVisualAssetType($existingItem->type)
            && $existingItem->semanticSectionType() !== ServiceSectionType::Other;
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function resolveMergedSectionType(
        ChurchServiceItem $existingItem,
        array $incomingItem,
    ): string {
        $incomingSectionType = ServiceSectionType::from($incomingItem['section_type']);
        $existingSectionType = $existingItem->semanticSectionType();

        if ($incomingSectionType === ServiceSectionType::Other && $existingSectionType !== ServiceSectionType::Other) {
            return $existingSectionType->value;
        }

        return $incomingSectionType->value;
    }

    /**
     * Independently authored lists rarely share the identifier columns, so their
     * only remaining tie is the visible title. This applies to every item type:
     * an order of service and a detected run both name the bible reading, and
     * without this the run would duplicate rather than match it.
     */
    private function shouldUseCrossSourceTitleMatch(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource
    ): bool {
        return ! $this->sourcesShareMergeAuthority($this->sourceForExistingItem($existingItem), $incomingSource);
    }

    /**
     * Whether two readings name the same passage.
     *
     * A plan writes "Joshua 1" where a run reports "Joshua 1:1-9", and neither
     * normalised-title comparison would pair them. ScriptureReferenceResolver
     * settles it on verse spans instead: it accepts subrange and split forms
     * while rejecting a crossing overlap, where each side reads past the other —
     * two genuinely different readings that must stay separate items.
     *
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function hasAgreeingScriptureReference(ChurchServiceItem $existingItem, array $incomingItem): bool
    {
        if ($incomingItem['type'] !== 'bibles') {
            return false;
        }

        // Both fields are offered rather than one preferred: OpenLP keeps the
        // canonical reference in title and an unparseable copyright header in
        // source_title, so preferring either field alone silences half the corpus.
        return $this->scriptureResolver->anyReferencesAgree(
            [$existingItem->title, $existingItem->source_title],
            [$incomingItem['title'], $incomingItem['source_title']],
        );
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function hasMatchingNormalisedTitle(ChurchServiceItem $existingItem, array $incomingItem): bool
    {
        $existingTitles = $this->normalisedTitlesForExistingItem($existingItem);
        $incomingTitles = $this->normalisedTitlesForIncomingItem($incomingItem);

        return $existingTitles !== [] && $incomingTitles !== [] && array_intersect($existingTitles, $incomingTitles) !== [];
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function typesCanDescribeTheSameItem(ChurchServiceItem $existingItem, array $incomingItem): bool
    {
        if ($existingItem->type === $incomingItem['type']) {
            return true;
        }

        $existingSectionType = $existingItem->semanticSectionType();
        $incomingSectionType = ServiceSectionType::from($incomingItem['section_type']);

        if ($existingSectionType !== ServiceSectionType::Other && $existingSectionType === $incomingSectionType) {
            return true;
        }

        return $this->isVisualAssetType($existingItem->type)
            || $this->isVisualAssetType($incomingItem['type']);
    }

    /**
     * OpenLP often names a slide by filename while the email names its purpose.
     * This deliberately narrow fingerprint only applies when one side is a visual
     * asset and the other is a children's-talk entry.
     *
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function hasMatchingAssetFingerprint(ChurchServiceItem $existingItem, array $incomingItem): bool
    {
        if (! $this->isVisualAssetType($existingItem->type) && ! $this->isVisualAssetType($incomingItem['type'])) {
            return false;
        }

        $existingSectionType = $existingItem->semanticSectionType();
        $incomingSectionType = ServiceSectionType::from($incomingItem['section_type']);

        if (! in_array(ServiceSectionType::ChildrensTalk, [$existingSectionType, $incomingSectionType], true)) {
            return false;
        }

        $existingFingerprints = $this->assetFingerprints([$existingItem->title, $existingItem->source_title]);
        $incomingFingerprints = $this->assetFingerprints([$incomingItem['title'], $incomingItem['source_title']]);

        return $existingFingerprints !== []
            && $incomingFingerprints !== []
            && array_intersect($existingFingerprints, $incomingFingerprints) !== [];
    }

    /**
     * @param  array<int, string|null>  $titles
     * @return list<string>
     */
    private function assetFingerprints(array $titles): array
    {
        $ignoredTokens = [
            'family', 'talk', 'child', 'children', 'childrens', 'see', 'pp',
            'presentation', 'presentations', 'slide', 'slides', 'image', 'images',
            'january', 'february', 'march', 'april', 'may', 'june', 'july',
            'august', 'september', 'october', 'november', 'december',
        ];
        $fingerprints = [];

        foreach ($titles as $title) {
            if (! is_string($title) || trim($title) === '') {
                continue;
            }

            $withBoundaries = preg_replace('/(?<=[a-z])(?=[A-Z0-9])|(?<=[0-9])(?=[A-Za-z])/', ' ', $title) ?? $title;
            $tokens = preg_split('/[^a-z0-9]+/', strtolower($withBoundaries), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_filter(
                $tokens,
                fn (string $token): bool => ! ctype_digit($token) && ! in_array($token, $ignoredTokens, true),
            ));
            $fingerprint = implode(' ', $tokens);

            if ($fingerprint !== '' && ! in_array($fingerprint, $fingerprints, true)) {
                $fingerprints[] = $fingerprint;
            }
        }

        return $fingerprints;
    }

    private function isVisualAssetType(string $type): bool
    {
        return in_array(strtolower($type), ['images', 'presentations'], true);
    }

    /**
     * @return list<string>
     */
    private function normalisedTitlesForExistingItem(ChurchServiceItem $existingItem): array
    {
        return $this->uniqueNormalisedTitles([
            $existingItem->title,
            $existingItem->source_title,
            $existingItem->openlp_search_title,
        ]);
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return list<string>
     */
    private function normalisedTitlesForIncomingItem(array $incomingItem): array
    {
        return $this->uniqueNormalisedTitles([
            $incomingItem['title'],
            $incomingItem['source_title'],
            $incomingItem['openlp_search_title'],
        ]);
    }

    /**
     * Uses the punctuation-insensitive comparison form so a stray comma or curly
     * quote between two hand-authored lists never blocks an anchor.
     *
     * @param  array<int, string|null>  $values
     * @return list<string>
     */
    private function uniqueNormalisedTitles(array $values): array
    {
        $normalised = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $canonical = Song::matchKey($value);
            if ($canonical === '' || in_array($canonical, $normalised, true)) {
                continue;
            }

            $normalised[] = $canonical;
        }

        return $normalised;
    }

    private function canUsePositionFallback(ChurchServiceItem $existingItem, ChurchServiceItemSource $incomingSource): bool
    {
        return $this->sourcesShareMergeAuthority($this->sourceForExistingItem($existingItem), $incomingSource);
    }

    /**
     * Whether an existing row the incoming list never mentioned should go.
     *
     * Silence only means absence when the writer was stating the whole list. An
     * OpenLP export carries slide-backed items only, an email plan predates the
     * service, and a run reports what it heard — none of the three is trying to be
     * complete, so none of them may read another's entry as deleted. Three writes
     * do state a complete list: an explicit replace, a source restating its own
     * earlier import, and a manual save, where the admin saw the row on screen and
     * took it off.
     */
    private function shouldDeleteUnmatchedItem(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource,
        bool $replaceMode
    ): bool {
        if ($replaceMode) {
            return true;
        }

        $existingSource = $this->sourceForExistingItem($existingItem);

        if ($incomingSource === ChurchServiceItemSource::Manual) {
            // Except a song the run heard: the admin was reading a plan, and the
            // recording is the better witness to what was actually sung.
            return ! ($existingSource->isDetected() && $this->isSongType($existingItem->type));
        }

        return $existingSource === $incomingSource;
    }

    private function shouldKeepExistingPosition(
        ChurchServiceItemSource $existingSource,
        ChurchServiceItemSource $incomingSource
    ): bool {
        if ($this->incomingStatesTheOrder($incomingSource)) {
            return false;
        }

        return ! $this->sourcesShareMergeAuthority($existingSource, $incomingSource);
    }

    /**
     * Whether this source's positions are the order, whatever else the list holds.
     *
     * Only a manual save qualifies. The anchoring rules exist because a partial list
     * cannot be read as a reordering of a fuller one — an OpenLP export carries only
     * slide-backed items and a run only what it heard, so neither one's sequence may
     * push aside an entry it never saw. A manual save is the exception on the same
     * grounds {@see shouldDeleteUnmatchedItem()} lets it delete: the admin was looking
     * at the whole list, in order, and dragged it into the order they wanted.
     */
    private function incomingStatesTheOrder(ChurchServiceItemSource $incomingSource): bool
    {
        return $incomingSource === ChurchServiceItemSource::Manual;
    }

    /**
     * Whether the existing order has to be anchored to rather than replaced.
     *
     * A manual save states its own order (see {@see incomingStatesTheOrder()}), but only
     * while every existing row is accounted for. A preserved row is one the save neither
     * matched nor may delete — a livestream-detected song, say — and the incoming list has
     * no position to offer it, so interleaving it is exactly what anchoring is for.
     *
     * @param  list<ChurchServiceItem>  $preservedItems
     */
    private function shouldAnchorOrder(
        bool $isCrossSourceMerge,
        array $preservedItems,
        ChurchServiceItemSource $incomingSource
    ): bool {
        if (! $isCrossSourceMerge) {
            return false;
        }

        return $preservedItems !== [] || ! $this->incomingStatesTheOrder($incomingSource);
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function resolveOpenLpSearchTitle(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        bool $preserveExistingIdentity
    ): ?string {
        if ($preserveExistingIdentity) {
            return $existingItem->openlp_search_title ?? $incomingItem['openlp_search_title'];
        }

        return $incomingItem['openlp_search_title'] ?? $existingItem->openlp_search_title;
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function resolveTitle(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        bool $preserveExistingIdentity
    ): string {
        if ($preserveExistingIdentity) {
            return $existingItem->title;
        }

        return $incomingItem['title'];
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function resolveSourceTitle(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        bool $preserveExistingIdentity
    ): ?string {
        if ($preserveExistingIdentity) {
            return $existingItem->source_title ?? $incomingItem['source_title'];
        }

        if ($this->isSongType($existingItem->type)) {
            return $incomingItem['source_title'] ?? $existingItem->source_title;
        }

        return $incomingItem['source_title'];
    }

    /**
     * @param  array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function resolveSongId(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        bool $preserveExistingIdentity
    ): ?int {
        $incomingIsExplicit = $this->hasExplicitSongLink($incomingItem['metadata']);
        $existingIsExplicit = $this->hasExplicitSongLink($existingItem->metadata);

        // A link a person chose from the catalogue outranks every rule below it:
        // the identity lattice exists to stop one source's *inference* rewriting
        // another's, and a deliberate choice is not an inference.
        if ($incomingIsExplicit && $incomingItem['song_id'] !== null) {
            return $incomingItem['song_id'];
        }

        if ($existingIsExplicit && $existingItem->song_id !== null) {
            return $existingItem->song_id;
        }

        if ($preserveExistingIdentity) {
            return $existingItem->song_id ?? $incomingItem['song_id'];
        }

        return $incomingItem['song_id'] ?? $existingItem->song_id;
    }

    /**
     * Whether a song link on this item was chosen by a person rather than resolved.
     *
     * The admin form's syncPayload() records the chosen song's canonical key
     * alongside the id, which is also what makes the choice survive a later
     * ChurchServiceSongLinker pass. Its presence is therefore the one durable
     * marker of a deliberate link.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    private function hasExplicitSongLink(?array $metadata): bool
    {
        $canonicalKey = $metadata['linked_song_canonical_key'] ?? null;

        return is_string($canonicalKey) && trim($canonicalKey) !== '';
    }

    private function resolveSource(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource,
        bool $preserveExistingIdentity
    ): ChurchServiceItemSource {
        $existingSource = $this->sourceForExistingItem($existingItem);

        if ($this->sourcesShareMergeAuthority($existingSource, $incomingSource)) {
            return $incomingSource;
        }

        // Observing an item does not make the run its author. Provenance drives
        // the authority rules on the next merge, so handing it over would let a
        // detected run quietly demote what OpenLP or the email recorded — or let a
        // re-parsed email inherit a row a person had already settled.
        if (
            $preserveExistingIdentity
            || $incomingSource->isDetected()
            || $this->isSongType($existingItem->type)
            || ($incomingSource === ChurchServiceItemSource::OpenLp && $existingSource->isHumanProvided())
        ) {
            return $existingSource;
        }

        return $incomingSource;
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<string, mixed>
     */
    private function resolveMetadata(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $existingSource,
        ChurchServiceItemSource $incomingSource,
        bool $preserveExistingIdentity
    ): array {
        $metadata = $this->withSurvivingExplicitSongLink(
            $this->mergeMetadataForSources($existingItem, $incomingItem, $existingSource, $incomingSource, $preserveExistingIdentity),
            $existingItem,
            $incomingItem,
        );

        return $this->withSourceEvidence($metadata, $existingItem, $incomingItem, $incomingSource);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<string, mixed>
     */
    private function withSourceEvidence(
        ?array $metadata,
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource,
    ): array {
        $metadata ??= [];
        $evidence = $this->sourceEvidenceFromMetadata($existingItem->metadata);
        $existingSource = $this->sourceForExistingItem($existingItem);

        if (! isset($evidence[$existingSource->value])) {
            $evidence[$existingSource->value] = [
                'titles' => $this->uniqueEvidenceTitles([$existingItem->title, $existingItem->source_title]),
            ];
        }

        $incomingEvidence = $this->sourceEvidenceFromMetadata($incomingItem['metadata']);
        $evidence = array_replace_recursive($evidence, $incomingEvidence);
        $incomingTitles = [
            ...($evidence[$incomingSource->value]['titles'] ?? []),
            $incomingItem['title'],
            $incomingItem['source_title'],
            ...($incomingItem['metadata']['additional_source_titles'] ?? []),
        ];
        $evidence[$incomingSource->value] = [
            'titles' => $this->uniqueEvidenceTitles($incomingTitles),
        ];

        unset($metadata['additional_source_titles']);
        $metadata['source_evidence'] = $evidence;

        return $metadata;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, array{titles:list<string>}>
     */
    private function sourceEvidenceFromMetadata(?array $metadata): array
    {
        $rawEvidence = $metadata['source_evidence'] ?? null;

        if (! is_array($rawEvidence)) {
            return [];
        }

        $evidence = [];

        foreach ($rawEvidence as $source => $entry) {
            if (! is_string($source) || ! is_array($entry)) {
                continue;
            }

            $titles = is_array($entry['titles'] ?? null) ? $entry['titles'] : [];
            $evidence[$source] = ['titles' => $this->uniqueEvidenceTitles($titles)];
        }

        return $evidence;
    }

    /**
     * @param  array<int, mixed>  $titles
     * @return list<string>
     */
    private function uniqueEvidenceTitles(array $titles): array
    {
        $unique = [];

        foreach ($titles as $title) {
            $normalised = $this->normaliseString($title);

            if ($normalised !== null && ! in_array($normalised, $unique, true)) {
                $unique[] = $normalised;
            }
        }

        return $unique;
    }

    /**
     * Keep the marker that made a link explicit alongside the link itself.
     *
     * {@see resolveSongId()} lets a deliberate link outrank the source lattice, so
     * the marker has to survive the same merge — otherwise the link stays but stops
     * counting as deliberate on the next one, and the following import quietly
     * overwrites it.
     *
     * @param  array<string, mixed>|null  $merged
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<string, mixed>|null
     */
    private function withSurvivingExplicitSongLink(
        ?array $merged,
        ChurchServiceItem $existingItem,
        array $incomingItem,
    ): ?array {
        $explicitKey = match (true) {
            $this->hasExplicitSongLink($incomingItem['metadata']) => $incomingItem['metadata']['linked_song_canonical_key'] ?? null,
            $this->hasExplicitSongLink($existingItem->metadata) => $existingItem->metadata['linked_song_canonical_key'] ?? null,
            default => null,
        };

        if ($explicitKey === null) {
            return $merged;
        }

        return [...$merged ?? [], 'linked_song_canonical_key' => $explicitKey];
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<string, mixed>|null
     */
    private function mergeMetadataForSources(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $existingSource,
        ChurchServiceItemSource $incomingSource,
        bool $preserveExistingIdentity
    ): ?array {
        $existingMetadata = is_array($existingItem->metadata) ? $existingItem->metadata : null;
        $incomingMetadata = $incomingItem['metadata'];

        // The incoming source still contributes what the existing row has no value
        // for; it just cannot overwrite the keys the owning source already set.
        if ($preserveExistingIdentity) {
            return $this->mergeMetadata($incomingMetadata, $existingMetadata);
        }

        if (
            $this->isSongType($existingItem->type)
            && $incomingSource === ChurchServiceItemSource::OpenLp
            && $existingSource->isHumanProvided()
        ) {
            return $this->mergeMetadata($existingMetadata, $incomingMetadata);
        }

        if (
            $this->isSongType($existingItem->type)
            && $incomingSource->isHumanProvided()
            && $existingSource === ChurchServiceItemSource::OpenLp
        ) {
            return $this->mergeMetadata($incomingMetadata, $existingMetadata);
        }

        // A detected run contributes its own metadata (confidence, timings) but must
        // not drop what the order of service already recorded against the entry.
        if (
            $incomingSource->isDetected()
            && ! $this->sourcesShareMergeAuthority($existingSource, $incomingSource)
        ) {
            return $this->mergeMetadata($incomingMetadata, $existingMetadata);
        }

        return $incomingMetadata;
    }

    /**
     * @param  array<string, mixed>|null  $base
     * @param  array<string, mixed>|null  $overlay
     * @return array<string, mixed>|null
     */
    private function mergeMetadata(?array $base, ?array $overlay): ?array
    {
        $base = $base ?? [];
        $overlay = $overlay ?? [];
        $merged = $this->metadataWithoutPromotedSectionType(array_merge($base, $overlay)) ?? [];

        return $merged !== [] ? $merged : null;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function metadataWithoutPromotedSectionType(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        unset($metadata['section_type'], $metadata['email_type']);

        return $metadata === [] ? null : $metadata;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $metadata
     */
    private function resolveSectionType(
        array $item,
        string $type,
        string $title,
        ?array $metadata,
    ): ServiceSectionType {
        $incomingSectionType = $item['section_type'] ?? null;

        if (is_string($incomingSectionType)) {
            $resolved = ServiceSectionType::tryFrom($incomingSectionType);

            if ($resolved instanceof ServiceSectionType) {
                return $resolved;
            }
        }

        $metadataType = $metadata['section_type'] ?? $metadata['email_type'] ?? null;

        if (is_string($metadataType)) {
            $resolved = ServiceSectionType::tryFrom($metadataType);

            if ($resolved instanceof ServiceSectionType) {
                return $resolved;
            }
        }

        return match (strtolower($type)) {
            'songs' => ServiceSectionType::Song,
            'bibles' => ServiceSectionType::BibleReading,
            default => ServiceSectionType::inferFromTitle($title),
        };
    }

    /**
     * Whether the existing entry owns its identity against this incoming source.
     *
     * Identity is the axis each source is separately good at, and the answer must
     * not depend on which import happened to run last:
     *
     *  - a detected run infers everything it names from audio, so it never rewrites
     *    an entry another source authored — a reading or a notice as much as a song;
     *  - OpenLP identifies the songs and readings it puts on the screen, where the
     *    email plan is typed by hand ahead of the service and is often approximate;
     *  - a row a person reviewed is not a draft for the next re-parse of the same
     *    email to redo.
     *
     * A manual save is absent from the list on purpose: it is the one write allowed
     * to correct any of them.
     */
    private function shouldPreserveExistingIdentity(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource
    ): bool {
        $existingSource = $this->sourceForExistingItem($existingItem);

        if ($incomingSource->isDetected()) {
            return $existingSource !== $incomingSource;
        }

        // Asked of the evidence rather than the `source` column, because the column
        // names whoever created the row and provenance never transfers on a match.
        // An OpenLP export that corrected an emailed song leaves the row saying
        // "email", and reading only that would let the next parse of the same email
        // undo the correction — the arrival order deciding the outcome after all.
        $evidence = $existingItem->provenanceSources();

        if (
            in_array(ChurchServiceItemSource::Manual, $evidence, true)
            && $incomingSource !== ChurchServiceItemSource::Manual
        ) {
            return true;
        }

        return $incomingSource === ChurchServiceItemSource::Email
            && $this->isIdentifiedType($existingItem->type)
            && in_array(ChurchServiceItemSource::OpenLp, $evidence, true);
    }

    /**
     * The item types OpenLP puts on a slide, and therefore identifies exactly.
     */
    private function isIdentifiedType(string $type): bool
    {
        return in_array($type, ['songs', 'bibles'], true);
    }

    /**
     * Whether two sources are writing the same list rather than merging two lists.
     *
     * Only a source restating its own earlier import qualifies. Positional and
     * wholesale-replacement reasoning is sound within one list and unsound between
     * two independently authored ones, which is every other pairing here.
     */
    private function sourcesShareMergeAuthority(
        ChurchServiceItemSource $existingSource,
        ChurchServiceItemSource $incomingSource
    ): bool {
        return $existingSource === $incomingSource;
    }

    private function sourceForExistingItem(ChurchServiceItem $existingItem): ChurchServiceItemSource
    {
        return $existingItem->source ?? ChurchServiceItemSource::OpenLp;
    }

    private function isSongType(string $type): bool
    {
        return $type === 'songs';
    }

    private function normaliseSource(ChurchServiceItemSource|string $source): ChurchServiceItemSource
    {
        if ($source instanceof ChurchServiceItemSource) {
            return $source;
        }

        $normalised = ChurchServiceItemSource::tryFrom($source);
        if (! $normalised instanceof ChurchServiceItemSource) {
            throw new RuntimeException('Incoming service items must specify a valid source.');
        }

        return $normalised;
    }

    private function resequenceActiveItems(ChurchService $churchService): void
    {
        $activeItems = $churchService->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($activeItems->isEmpty()) {
            return;
        }

        // Phase 1: Move all items to temporary positions above any current max.
        // This frees all current positions so we can safely resequence without
        // triggering the unique constraint when items need to cross each other.
        $maxPosition = (int) $activeItems->max('position');
        $tempBase = $maxPosition + $activeItems->count() + 1;

        foreach ($activeItems->values() as $index => $item) {
            $temporaryPosition = $tempBase + $index;

            if ($item->position === $temporaryPosition) {
                continue;
            }

            ChurchServiceItem::query()->withTrashed()
                ->whereKey($item->id)
                ->update(['position' => $temporaryPosition]);
        }

        // Phase 2: Apply final sequential positions 1, 2, 3...
        foreach ($activeItems->values() as $index => $item) {
            $expectedPosition = $index + 1;

            ChurchServiceItem::query()->withTrashed()
                ->whereKey($item->id)
                ->update(['position' => $expectedPosition]);
        }
    }

    private function assertUniqueActivePositions(ChurchService $churchService): void
    {
        if ($this->hasDuplicateActivePositions($churchService)) {
            throw new RuntimeException('Active service items must have unique positions per service.');
        }
    }

    private function hasDuplicateActivePositions(ChurchService $churchService): bool
    {
        return $churchService
            ->items()
            ->select('position')
            ->groupBy('position')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    /**
     * Whether this sync merges lists that were authored independently. Only then
     * does anchoring matter: when every surviving item shares merge authority
     * with the incoming source, the incoming positions are simply authoritative.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  list<ChurchServiceItem>  $preservedItems
     */
    private function isCrossSourceMerge(
        array $plan,
        array $preservedItems,
        ChurchServiceItemSource $incomingSource
    ): bool {
        foreach ($preservedItems as $preservedItem) {
            if (! $this->sourcesShareMergeAuthority($this->sourceForExistingItem($preservedItem), $incomingSource)) {
                return true;
            }
        }

        foreach ($plan as $entry) {
            if ($entry['kind'] !== 'update') {
                continue;
            }

            /** @var ChurchServiceItem $existingItem */
            $existingItem = $entry['existing_item'];

            if (! $this->sourcesShareMergeAuthority($this->sourceForExistingItem($existingItem), $incomingSource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sequence a cross-source merge by anchoring the two lists to each other.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  list<ChurchServiceItem>  $preservedItems
     */
    private function applyAnchoredOrder(
        ChurchService $churchService,
        array $plan,
        array $preservedItems,
        ChurchServiceItemSource $incomingSource
    ): void {
        $resolverPlan = array_map(
            fn (array $entry): array => [
                'kind' => (string) $entry['kind'],
                'existing_position' => $entry['existing_position'] === null ? null : (int) $entry['existing_position'],
            ],
            $plan,
        );

        $ordered = $this->orderResolver->resolve(
            $resolverPlan,
            array_map(fn (ChurchServiceItem $item): int => $item->position, $preservedItems),
            $incomingSource->isDetected(),
        );

        $this->stageExistingItems($churchService, [
            ...$this->matchedItems($plan),
            ...$preservedItems,
        ]);

        $position = 0;

        foreach ($ordered as $slot) {
            $position++;

            if ($slot['source'] === 'preserved') {
                $this->repositionItem($preservedItems[$slot['index']], $position);

                continue;
            }

            $this->writePlanEntry($churchService, $plan[$slot['index']], $incomingSource, $position);
        }
    }

    /**
     * Sequence a same-authority sync, where the incoming positions are canonical.
     *
     * @param  list<array<string, mixed>>  $plan
     */
    private function applyIncomingPositions(
        ChurchService $churchService,
        array $plan,
        ChurchServiceItemSource $incomingSource
    ): void {
        $this->stageExistingItems($churchService, $this->matchedItems($plan));

        $occupiedPositions = [];

        foreach ($plan as $entry) {
            $resolvedPosition = $this->claimNextAvailablePosition((int) $entry['desired_position'], $occupiedPositions);

            $this->writePlanEntry($churchService, $entry, $incomingSource, $resolvedPosition);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function writePlanEntry(
        ChurchService $churchService,
        array $entry,
        ChurchServiceItemSource $incomingSource,
        int $position
    ): void {
        if ($entry['kind'] === 'update') {
            /** @var ChurchServiceItem $existingItem */
            $existingItem = $entry['existing_item'];
            /** @var array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>} $incomingItem */
            $incomingItem = $entry['incoming_item'];
            /** @var array<string, mixed> $rawIncomingItem */
            $rawIncomingItem = $entry['raw_incoming_item'];

            $this->updateMatchedItem($existingItem, $incomingItem, $incomingSource, $position, $rawIncomingItem);

            return;
        }

        /** @var array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>} $normalizedItem */
        $normalizedItem = $entry['normalized_item'];
        /** @var array<string, mixed> $rawItem */
        $rawItem = $entry['raw_item'];

        $createData = [
            'position' => $position,
            'type' => $normalizedItem['type'],
            'section_type' => $normalizedItem['section_type'],
            'source' => $incomingSource->value,
            'title' => $normalizedItem['title'],
            'source_title' => $normalizedItem['source_title'],
            'openlp_search_title' => $normalizedItem['openlp_search_title'],
            'song_id' => $normalizedItem['song_id'],
            'metadata' => $this->metadataWithInitialSourceEvidence($normalizedItem, $incomingSource),
        ];

        if (isset($rawItem['livestream_processing_id'])) {
            $createData['livestream_processing_id'] = $rawItem['livestream_processing_id'];
        }

        if (isset($rawItem['livestream_service_section_id'])) {
            $createData['livestream_service_section_id'] = $rawItem['livestream_service_section_id'];
        }

        $churchService->items()->create($createData);
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $item
     * @return array<string, mixed>
     */
    private function metadataWithInitialSourceEvidence(
        array $item,
        ChurchServiceItemSource $source,
    ): array {
        $metadata = $item['metadata'] ?? [];
        $evidence = $this->sourceEvidenceFromMetadata($metadata);
        $titles = [
            ...($evidence[$source->value]['titles'] ?? []),
            $item['title'],
            $item['source_title'],
            ...($metadata['additional_source_titles'] ?? []),
        ];
        $evidence[$source->value] = ['titles' => $this->uniqueEvidenceTitles($titles)];

        unset($metadata['additional_source_titles']);
        $metadata['source_evidence'] = $evidence;

        return $metadata;
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @return list<ChurchServiceItem>
     */
    private function matchedItems(array $plan): array
    {
        $matched = [];

        foreach ($plan as $entry) {
            if ($entry['kind'] === 'update') {
                /** @var ChurchServiceItem $existingItem */
                $existingItem = $entry['existing_item'];
                $matched[] = $existingItem;
            }
        }

        return $matched;
    }

    /**
     * Park every surviving item above the current maximum before rewriting
     * positions, so items crossing each other never trip the unique constraint.
     *
     * @param  list<ChurchServiceItem>  $items
     */
    private function stageExistingItems(ChurchService $churchService, array $items): void
    {
        if ($items === []) {
            return;
        }

        $maxPosition = (int) ChurchServiceItem::query()->withTrashed()
            ->where('church_service_id', $churchService->id)
            ->max('position');

        foreach ($items as $index => $item) {
            $this->repositionItem($item, $maxPosition + $index + 1);
        }
    }

    private function repositionItem(ChurchServiceItem $item, int $position): void
    {
        ChurchServiceItem::query()->withTrashed()
            ->whereKey($item->id)
            ->update(['position' => $position]);

        $item->position = $position;
        $item->syncOriginalAttribute('position');
    }

    /**
     * @param  array<int, bool>  $occupiedPositions
     */
    private function claimNextAvailablePosition(int $desiredPosition, array &$occupiedPositions): int
    {
        $resolvedPosition = $desiredPosition;

        while (isset($occupiedPositions[$resolvedPosition])) {
            $resolvedPosition++;
        }

        $occupiedPositions[$resolvedPosition] = true;

        return $resolvedPosition;
    }

    private function normaliseString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalised = trim((string) preg_replace('/\s+/', ' ', $value));

        return $normalised === '' ? null : $normalised;
    }

    /**
     * Review signals a detected run raises about the merge as a whole.
     *
     * A run that simply misses a planned song is lossy, not wrong — that is what
     * filling gaps means. What is worth a reviewer's time is evidence the run
     * identified something incorrectly: a planned song missing while an
     * unexpected one appears (a substitution), an unexpected song on its own,
     * or too few anchors to place the items it never matched.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  list<ChurchServiceItem>  $preservedItems
     * @return array<int, array<string, mixed>>
     */
    private function mergeEvidenceConflicts(
        array $plan,
        array $preservedItems,
        ChurchServiceItemSource $incomingSource,
        bool $isCrossSourceMerge
    ): array {
        if (! $isCrossSourceMerge || ! $incomingSource->isDetected()) {
            return [];
        }

        $missedSongs = array_values(array_filter(
            $preservedItems,
            fn (ChurchServiceItem $item): bool => $this->isSongType($item->type),
        ));

        $unexpectedSongs = array_values(array_filter(
            $plan,
            fn (array $entry): bool => $entry['kind'] === 'create'
                && $this->isSongType((string) $entry['normalized_item']['type']),
        ));

        $conflicts = [];

        if ($missedSongs !== [] && $unexpectedSongs !== []) {
            $conflicts[] = [
                'type' => 'song_substitution_suspected',
                'incoming_source' => $incomingSource->value,
                'missed_songs' => array_map(fn (ChurchServiceItem $item): array => $this->snapshotItem($item), $missedSongs),
                'unexpected_songs' => array_map(
                    fn (array $entry): string => (string) $entry['normalized_item']['title'],
                    $unexpectedSongs,
                ),
            ];
        } elseif ($unexpectedSongs !== []) {
            $conflicts[] = [
                'type' => 'unexpected_detected_song',
                'incoming_source' => $incomingSource->value,
                'unexpected_songs' => array_map(
                    fn (array $entry): string => (string) $entry['normalized_item']['title'],
                    $unexpectedSongs,
                ),
            ];
        }

        $orderConflict = $this->contradictedOrderConflict($plan, $incomingSource);

        if ($orderConflict !== null) {
            $conflicts[] = $orderConflict;
        }

        $coverageConflict = $this->thinAnchorCoverageConflict($plan, $preservedItems, $missedSongs, $incomingSource);

        if ($coverageConflict !== null) {
            $conflicts[] = $coverageConflict;
        }

        return $conflicts;
    }

    /**
     * The run and the existing list agree on which items happened but disagree on
     * their order.
     *
     * The detected order wins — the run is the record of what happened. But that
     * is precisely the case where "what happened" and "the run misread what
     * happened" are indistinguishable from the data, and where applying the
     * detected order rewrites a list a human may have authored deliberately. Every
     * other anchor arrangement produces the same list under either ordering rule,
     * so this is the only reordering worth a reviewer's attention.
     *
     * @param  list<array<string, mixed>>  $plan
     * @return array<string, mixed>|null
     */
    private function contradictedOrderConflict(array $plan, ChurchServiceItemSource $incomingSource): ?array
    {
        $anchoredPositions = [];

        foreach ($plan as $entry) {
            if ($entry['kind'] === 'update') {
                $anchoredPositions[] = (int) $entry['existing_position'];
            }
        }

        if (count($anchoredPositions) < 2) {
            return null;
        }

        $expectedOrder = $anchoredPositions;
        sort($expectedOrder);

        if ($expectedOrder === $anchoredPositions) {
            return null;
        }

        return [
            'type' => 'detected_order_contradicts_plan',
            'incoming_source' => $incomingSource->value,
            'existing_order' => $expectedOrder,
            'detected_order' => $anchoredPositions,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @param  list<ChurchServiceItem>  $preservedItems
     * @param  list<ChurchServiceItem>  $missedSongs
     * @return array<string, mixed>|null
     */
    private function thinAnchorCoverageConflict(
        array $plan,
        array $preservedItems,
        array $missedSongs,
        ChurchServiceItemSource $incomingSource
    ): ?array {
        // With everything anchored, nothing is being placed on guesswork and the
        // anchor count is irrelevant however small it is.
        if ($preservedItems === []) {
            return null;
        }

        $anchors = array_values(array_filter($plan, fn (array $entry): bool => $entry['kind'] === 'update'));

        $anchoredSongs = array_values(array_filter($anchors, function (array $entry): bool {
            /** @var ChurchServiceItem $existingItem */
            $existingItem = $entry['existing_item'];

            return $this->isSongType($existingItem->type);
        }));

        $existingSongCount = count($anchoredSongs) + count($missedSongs);

        if ($existingSongCount === 0) {
            return null;
        }

        $coverage = count($anchoredSongs) / $existingSongCount;

        if (count($anchors) >= self::MinimumAnchorCount && $coverage >= self::MinimumSongAnchorCoverage) {
            return null;
        }

        return [
            'type' => 'thin_anchor_coverage',
            'incoming_source' => $incomingSource->value,
            'anchor_count' => count($anchors),
            'song_anchor_coverage' => round($coverage, 3),
            'unplaced_items' => array_map(fn (ChurchServiceItem $item): array => $this->snapshotItem($item), $preservedItems),
        ];
    }

    private function shouldFlagPreservedSongConflict(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource
    ): bool {
        // A detected run not finding a planned song is the expected, lossy case —
        // it is what "fills gaps in the order of service" means. Flagging it would
        // send every merged service to the review inbox for no reviewable reason.
        if ($incomingSource->isDetected()) {
            return false;
        }

        return $this->isSongType($existingItem->type)
            && ! $this->sourcesShareMergeAuthority($this->sourceForExistingItem($existingItem), $incomingSource);
    }

    private function shouldFlagPreservedExistingItemConflict(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource
    ): bool {
        return $incomingSource === ChurchServiceItemSource::Email
            && $this->sourceForExistingItem($existingItem) === ChurchServiceItemSource::OpenLp
            && ! $this->isSongType($existingItem->type);
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<int, array<string, mixed>>
     */
    private function conflictsForMatchedItem(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource,
        bool $preserveExistingIdentity
    ): array {
        if (! $preserveExistingIdentity) {
            return [];
        }

        // Scoped to songs on purpose. A plan naming the chapter where the export
        // names the verses is the normal shape of a corroborated reading, not a
        // disagreement — flagging it would send most services to the inbox.
        if (! $this->isSongType($existingItem->type)) {
            return [];
        }

        // Deferring to the order of service is the designed outcome for a detected
        // run, not a disagreement a reviewer needs to adjudicate.
        if ($incomingSource->isDetected()) {
            return [];
        }

        $fieldDifferences = $this->songFieldDifferences($existingItem, $incomingItem);
        if ($fieldDifferences === []) {
            return [];
        }

        return [[
            'type' => 'ignored_incoming_song_metadata',
            'incoming_source' => $incomingSource->value,
            'existing_item' => $this->snapshotItem($existingItem),
            'ignored_fields' => $fieldDifferences,
        ]];
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<int, array<string, mixed>>
     */
    private function songFieldDifferences(ChurchServiceItem $existingItem, array $incomingItem): array
    {
        $differences = [];

        $comparisons = [
            'title' => [$existingItem->title, $incomingItem['title']],
            'openlp_search_title' => [$existingItem->openlp_search_title, $incomingItem['openlp_search_title']],
            'song_id' => [$existingItem->song_id, $incomingItem['song_id']],
            'metadata' => [
                $this->normaliseArray($existingItem->metadata),
                $this->normaliseArray($incomingItem['metadata']),
            ],
        ];

        foreach ($comparisons as $field => [$existingValue, $incomingValue]) {
            if ($existingValue === $incomingValue) {
                continue;
            }

            $differences[] = [
                'field' => $field,
                'existing' => $existingValue,
                'incoming' => $incomingValue,
            ];
        }

        return $differences;
    }

    /**
     * @param  array<int|string, mixed>|null  $value
     * @return array<int|string, mixed>|null
     */
    private function normaliseArray(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        ksort($value);

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->normaliseArray($nested);
            }
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotItem(ChurchServiceItem $item): array
    {
        return [
            'id' => $item->id,
            'position' => $item->position,
            'type' => $item->type,
            'section_type' => $item->semanticSectionType()->value,
            'source' => $this->sourceForExistingItem($item)->value,
            'title' => $item->title,
            'source_title' => $item->source_title,
            'openlp_search_title' => $item->openlp_search_title,
            'song_id' => $item->song_id,
            'metadata' => $this->normaliseArray($item->metadata),
        ];
    }
}
