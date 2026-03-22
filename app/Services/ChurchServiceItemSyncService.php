<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChurchServiceItemSyncService
{
    /**
     * @param  array<int, array<string, mixed>>  $incomingItems
     * @param  array{replace_mode?:bool}  $options
     * @return array{
     *     conflicts: array<int, array<string, mixed>>
     * }
     */
    public function sync(
        ChurchService $churchService,
        array $incomingItems,
        ChurchServiceItemSource|string $incomingSource = ChurchServiceItemSource::OPENLP,
        array $options = [],
    ): array {
        $incomingSource = $this->normaliseSource($incomingSource);
        $replaceMode = (bool) ($options['replace_mode'] ?? false);

        return DB::transaction(function () use ($churchService, $incomingItems, $incomingSource, $replaceMode): array {
            $lockedService = ChurchService::query()
                ->whereKey($churchService->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Collection<int, ChurchServiceItem> $existingItems */
            $existingItems = $lockedService->items()->withTrashed()->orderBy('id')->get();
            $matchedExistingItemIds = [];
            $seenPositions = [];
            $conflicts = [];

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
                    $matchConflicts = $this->updateMatchedItem($match, $incomingItem, $incomingSource);
                    $conflicts = [...$conflicts, ...$matchConflicts];
                    $matchedExistingItemIds[] = $match->id;

                    continue;
                }

                $created = $lockedService->items()->create([
                    'position' => $incomingItem['position'],
                    'type' => $incomingItem['type'],
                    'section_type' => $incomingItem['section_type'],
                    'source' => $incomingSource->value,
                    'title' => $incomingItem['title'],
                    'source_title' => $incomingItem['source_title'],
                    'openlp_search_title' => $incomingItem['openlp_search_title'],
                    'song_id' => $incomingItem['song_id'],
                    'metadata' => $incomingItem['metadata'],
                ]);

                $matchedExistingItemIds[] = $created->id;
            }

            foreach ($existingItems as $existingItem) {
                if (
                    ! in_array($existingItem->id, $matchedExistingItemIds, true)
                    && ! $existingItem->trashed()
                ) {
                    if ($this->shouldDeleteUnmatchedItem($existingItem, $incomingSource, $replaceMode)) {
                        $existingItem->delete();

                        continue;
                    }

                    if ($this->shouldFlagPreservedSongConflict($existingItem, $incomingSource)) {
                        $conflicts[] = [
                            'type' => 'preserved_existing_song',
                            'incoming_source' => $incomingSource->value,
                            'existing_item' => $this->snapshotItem($existingItem),
                        ];
                    }
                }
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
        /** @var ChurchServiceItem|null $match */
        $match = $existingItems->first(function (ChurchServiceItem $existingItem) use ($incomingItem, $matchedExistingItemIds, $incomingSource): bool {
            if (in_array($existingItem->id, $matchedExistingItemIds, true)) {
                return false;
            }

            if ($existingItem->type !== $incomingItem['type']) {
                return false;
            }

            $incomingSearchTitle = $incomingItem['openlp_search_title'];
            if ($incomingSearchTitle !== null && $existingItem->openlp_search_title === $incomingSearchTitle) {
                return true;
            }

            $incomingSourceTitle = $incomingItem['source_title'];
            if ($incomingSourceTitle !== null && $existingItem->source_title === $incomingSourceTitle) {
                return true;
            }

            if ($this->shouldUseCrossSourceSongTitleMatch($existingItem, $incomingItem, $incomingSource)) {
                return $this->hasMatchingNormalisedSongTitle($existingItem, $incomingItem);
            }

            return false;
        });

        return $match;
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
     * @return array<int, array<string, mixed>>
     */
    private function updateMatchedItem(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource
    ): array {
        if ($existingItem->trashed()) {
            $existingItem->restore();
        }

        $existingSource = $this->sourceForExistingItem($existingItem);
        $preserveOpenLpSongMetadata = $this->shouldPreserveOpenLpSongMetadata($existingItem, $incomingSource);
        $conflicts = $this->conflictsForMatchedItem(
            $existingItem,
            $incomingItem,
            $incomingSource,
            $preserveOpenLpSongMetadata,
        );
        $position = $this->shouldKeepExistingPosition($existingSource, $incomingSource)
            ? $existingItem->position
            : $incomingItem['position'];

        $existingItem->fill([
            'position' => $position,
            'type' => $incomingItem['type'],
            'section_type' => $incomingItem['section_type'],
            'source' => $this->resolveSource($existingItem, $incomingSource)->value,
            'title' => $this->resolveTitle($existingItem, $incomingItem, $preserveOpenLpSongMetadata),
            'source_title' => $this->resolveSourceTitle($existingItem, $incomingItem, $incomingSource),
            'openlp_search_title' => $this->resolveOpenLpSearchTitle($existingItem, $incomingItem, $preserveOpenLpSongMetadata),
            'song_id' => $this->resolveSongId($existingItem, $incomingItem, $preserveOpenLpSongMetadata),
            'metadata' => $this->resolveMetadata($existingItem, $incomingItem, $existingSource, $incomingSource),
        ]);

        $existingItem->save();

        return $conflicts;
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function shouldUseCrossSourceSongTitleMatch(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource
    ): bool {
        return $this->isSongType($incomingItem['type'])
            && ! $this->sourcesShareMergeAuthority($this->sourceForExistingItem($existingItem), $incomingSource);
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function hasMatchingNormalisedSongTitle(ChurchServiceItem $existingItem, array $incomingItem): bool
    {
        $existingTitles = $this->normalisedSongTitlesForExistingItem($existingItem);
        $incomingTitles = $this->normalisedSongTitlesForIncomingItem($incomingItem);

        return $existingTitles !== [] && $incomingTitles !== [] && array_intersect($existingTitles, $incomingTitles) !== [];
    }

    /**
     * @return list<string>
     */
    private function normalisedSongTitlesForExistingItem(ChurchServiceItem $existingItem): array
    {
        return $this->uniqueNormalisedSongTitles([
            $existingItem->title,
            $existingItem->source_title,
            $existingItem->openlp_search_title,
        ]);
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return list<string>
     */
    private function normalisedSongTitlesForIncomingItem(array $incomingItem): array
    {
        return $this->uniqueNormalisedSongTitles([
            $incomingItem['title'],
            $incomingItem['source_title'],
            $incomingItem['openlp_search_title'],
        ]);
    }

    /**
     * @param  array<int, string|null>  $values
     * @return list<string>
     */
    private function uniqueNormalisedSongTitles(array $values): array
    {
        $normalised = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $canonical = Song::canonicalizeKey($value);
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

    private function shouldDeleteUnmatchedItem(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource,
        bool $replaceMode
    ): bool {
        $existingSource = $this->sourceForExistingItem($existingItem);

        if ($this->sourcesShareMergeAuthority($existingSource, $incomingSource)) {
            return true;
        }

        if ($this->isSongType($existingItem->type)) {
            return $replaceMode;
        }

        if ($incomingSource === ChurchServiceItemSource::OPENLP && $existingSource->isHumanProvided()) {
            return false;
        }

        if ($incomingSource->isHumanProvided() && $existingSource === ChurchServiceItemSource::OPENLP) {
            return true;
        }

        return true;
    }

    private function shouldKeepExistingPosition(
        ChurchServiceItemSource $existingSource,
        ChurchServiceItemSource $incomingSource
    ): bool {
        return ! $this->sourcesShareMergeAuthority($existingSource, $incomingSource);
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function resolveOpenLpSearchTitle(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        bool $preserveOpenLpSongMetadata
    ): ?string {
        if ($preserveOpenLpSongMetadata) {
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
        bool $preserveOpenLpSongMetadata
    ): string {
        if ($preserveOpenLpSongMetadata) {
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
        ChurchServiceItemSource $incomingSource
    ): ?string {
        if (! $this->isSongType($existingItem->type)) {
            return $incomingItem['source_title'];
        }

        $existingSource = $this->sourceForExistingItem($existingItem);

        if ($existingSource->isHumanProvided() && $incomingSource === ChurchServiceItemSource::OPENLP) {
            return $existingItem->source_title ?? $incomingItem['source_title'];
        }

        if ($existingSource === ChurchServiceItemSource::OPENLP && $incomingSource->isHumanProvided()) {
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
        bool $preserveOpenLpSongMetadata
    ): ?int {
        if ($preserveOpenLpSongMetadata) {
            return $existingItem->song_id ?? $incomingItem['song_id'];
        }

        return $incomingItem['song_id'] ?? $existingItem->song_id;
    }

    private function resolveSource(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource
    ): ChurchServiceItemSource {
        $existingSource = $this->sourceForExistingItem($existingItem);

        if ($this->isSongType($existingItem->type) && ! $this->sourcesShareMergeAuthority($existingSource, $incomingSource)) {
            return $existingSource;
        }

        return $incomingSource;
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<string, mixed>|null
     */
    private function resolveMetadata(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $existingSource,
        ChurchServiceItemSource $incomingSource
    ): ?array {
        $existingMetadata = is_array($existingItem->metadata) ? $existingItem->metadata : null;
        $incomingMetadata = $incomingItem['metadata'];

        if (
            $this->isSongType($existingItem->type)
            && $incomingSource === ChurchServiceItemSource::OPENLP
            && $existingSource->isHumanProvided()
        ) {
            return $this->mergeMetadata($existingMetadata, $incomingMetadata);
        }

        if (
            $this->isSongType($existingItem->type)
            && $incomingSource->isHumanProvided()
            && $existingSource === ChurchServiceItemSource::OPENLP
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
        $merged = array_merge($base, $overlay);

        return $merged !== [] ? $merged : null;
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
            'songs' => ServiceSectionType::SONG,
            'bibles' => ServiceSectionType::BIBLE_READING,
            default => ServiceSectionType::inferFromTitle($title),
        };
    }

    private function shouldPreserveOpenLpSongMetadata(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource
    ): bool {
        return $this->isSongType($existingItem->type)
            && $incomingSource->isHumanProvided()
            && $this->sourceForExistingItem($existingItem) === ChurchServiceItemSource::OPENLP;
    }

    private function sourcesShareMergeAuthority(
        ChurchServiceItemSource $existingSource,
        ChurchServiceItemSource $incomingSource
    ): bool {
        return $existingSource === $incomingSource
            || ($existingSource->isHumanProvided() && $incomingSource->isHumanProvided());
    }

    private function sourceForExistingItem(ChurchServiceItem $existingItem): ChurchServiceItemSource
    {
        return $existingItem->source ?? ChurchServiceItemSource::OPENLP;
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

        foreach ($activeItems->values() as $index => $item) {
            $expectedPosition = $index + 1;

            if ($item->position === $expectedPosition) {
                continue;
            }

            $item->position = $expectedPosition;
            $item->save();
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

    private function normaliseString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalised = trim((string) preg_replace('/\s+/', ' ', $value));

        return $normalised === '' ? null : $normalised;
    }

    private function shouldFlagPreservedSongConflict(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource
    ): bool {
        return $this->isSongType($existingItem->type)
            && ! $this->sourcesShareMergeAuthority($this->sourceForExistingItem($existingItem), $incomingSource);
    }

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<int, array<string, mixed>>
     */
    private function conflictsForMatchedItem(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource,
        bool $preserveOpenLpSongMetadata
    ): array {
        if (! $preserveOpenLpSongMetadata) {
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
