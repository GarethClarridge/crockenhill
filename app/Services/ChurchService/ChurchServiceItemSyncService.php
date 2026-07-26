<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

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
        ChurchServiceItemSource|string $incomingSource = ChurchServiceItemSource::OpenLp,
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
            $pendingUpdates = [];
            $pendingCreates = [];

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
                    $preserveExistingSongIdentity = $this->shouldPreserveExistingSongIdentity($match, $incomingSource);
                    $matchConflicts = $this->conflictsForMatchedItem(
                        $match,
                        $incomingItem,
                        $incomingSource,
                        $preserveExistingSongIdentity,
                    );
                    $conflicts = [...$conflicts, ...$matchConflicts];
                    $pendingUpdates[] = [
                        'existing_item' => $match,
                        'incoming_item' => $incomingItem,
                        'raw_incoming_item' => $rawIncomingItem,
                        'desired_position' => $this->shouldKeepExistingPosition($existingSource, $incomingSource)
                            ? $match->position
                            : $incomingItem['position'],
                    ];
                    $matchedExistingItemIds[] = $match->id;

                    continue;
                }

                $pendingCreates[] = [
                    'normalized_item' => $incomingItem,
                    'raw_item' => $rawIncomingItem,
                ];
            }

            $positionsToPreserve = [];
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

                    $positionsToPreserve[$existingItem->position] = true;

                    if ($this->shouldFlagPreservedSongConflict($existingItem, $incomingSource)) {
                        $conflicts[] = [
                            'type' => 'preserved_existing_song',
                            'incoming_source' => $incomingSource->value,
                            'existing_item' => $this->snapshotItem($existingItem),
                        ];
                    }
                }
            }

            foreach ($itemsToDelete as $itemToDelete) {
                $itemToDelete->delete();
            }

            $this->stagePendingMatchedItems($lockedService, $pendingUpdates);

            foreach ($pendingUpdates as $pendingUpdate) {
                /** @var ChurchServiceItem $existingItem */
                $existingItem = $pendingUpdate['existing_item'];
                /** @var array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>} $incomingItem */
                $incomingItem = $pendingUpdate['incoming_item'];
                /** @var array<string, mixed> $rawIncomingItem */
                $rawIncomingItem = $pendingUpdate['raw_incoming_item'];
                $resolvedPosition = $this->claimNextAvailablePosition(
                    $pendingUpdate['desired_position'],
                    $positionsToPreserve,
                );

                $this->updateMatchedItem($existingItem, $incomingItem, $incomingSource, $resolvedPosition, $rawIncomingItem);
            }

            foreach ($pendingCreates as $pendingCreate) {
                /** @var array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>} $normalizedItem */
                $normalizedItem = $pendingCreate['normalized_item'];
                /** @var array<string, mixed> $rawItem */
                $rawItem = $pendingCreate['raw_item'];
                $resolvedPosition = $this->claimNextAvailablePosition($normalizedItem['position'], $positionsToPreserve);

                $createData = [
                    'position' => $resolvedPosition,
                    'type' => $normalizedItem['type'],
                    'section_type' => $normalizedItem['section_type'],
                    'source' => $incomingSource->value,
                    'title' => $normalizedItem['title'],
                    'source_title' => $normalizedItem['source_title'],
                    'openlp_search_title' => $normalizedItem['openlp_search_title'],
                    'song_id' => $normalizedItem['song_id'],
                    'metadata' => $normalizedItem['metadata'],
                ];

                if (isset($rawItem['livestream_processing_id'])) {
                    $createData['livestream_processing_id'] = $rawItem['livestream_processing_id'];
                }

                if (isset($rawItem['livestream_service_section_id'])) {
                    $createData['livestream_service_section_id'] = $rawItem['livestream_service_section_id'];
                }

                $created = $lockedService->items()->create($createData);

                $matchedExistingItemIds[] = $created->id;
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
        $preserveExistingSongIdentity = $this->shouldPreserveExistingSongIdentity($existingItem, $incomingSource);

        $fillData = [
            'position' => $position,
            'type' => $incomingItem['type'],
            'section_type' => $incomingItem['section_type'],
            'source' => $this->resolveSource($existingItem, $incomingSource)->value,
            'title' => $this->resolveTitle($existingItem, $incomingItem, $preserveExistingSongIdentity),
            'source_title' => $this->resolveSourceTitle($existingItem, $incomingItem, $incomingSource),
            'openlp_search_title' => $this->resolveOpenLpSearchTitle($existingItem, $incomingItem, $preserveExistingSongIdentity),
            'song_id' => $this->resolveSongId($existingItem, $incomingItem, $preserveExistingSongIdentity),
            'metadata' => $this->resolveMetadata($existingItem, $incomingItem, $existingSource, $incomingSource),
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

        if ($incomingSource === ChurchServiceItemSource::OpenLp && $existingSource->isHumanProvided()) {
            return false;
        }

        if ($incomingSource->isHumanProvided() && $existingSource === ChurchServiceItemSource::OpenLp) {
            return true;
        }

        if ($incomingSource->isDetected() && ! $existingSource->isDetected()) {
            return false;
        }

        if ($incomingSource->isHumanProvided() && $existingSource->isDetected()) {
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
        bool $preserveExistingSongIdentity
    ): ?string {
        if ($preserveExistingSongIdentity) {
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
        bool $preserveExistingSongIdentity
    ): string {
        if ($preserveExistingSongIdentity) {
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

        if ($existingSource->isHumanProvided() && $incomingSource === ChurchServiceItemSource::OpenLp) {
            return $existingItem->source_title ?? $incomingItem['source_title'];
        }

        if ($existingSource === ChurchServiceItemSource::OpenLp && $incomingSource->isHumanProvided()) {
            return $incomingItem['source_title'] ?? $existingItem->source_title;
        }

        if ($incomingSource->isDetected() && ! $this->sourcesShareMergeAuthority($existingSource, $incomingSource)) {
            return $existingItem->source_title ?? $incomingItem['source_title'];
        }

        return $incomingItem['source_title'];
    }

    /**
     * @param  array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     */
    private function resolveSongId(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        bool $preserveExistingSongIdentity
    ): ?int {
        if ($preserveExistingSongIdentity) {
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
            $this->isSongType($existingItem->type)
            && $incomingSource->isDetected()
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
     * Whether the existing song entry owns its identity against this incoming source.
     *
     * A detected (livestream) run infers songs from audio; the order of service is
     * the record of what was actually planned and sung. So a livestream run fills
     * gaps in the order of service and attaches its own provenance, but never
     * rewrites the title, search title, song link or metadata of an entry another
     * source already owns.
     */
    private function shouldPreserveExistingSongIdentity(
        ChurchServiceItem $existingItem,
        ChurchServiceItemSource $incomingSource
    ): bool {
        if (! $this->isSongType($existingItem->type)) {
            return false;
        }

        $existingSource = $this->sourceForExistingItem($existingItem);

        if ($incomingSource->isDetected()) {
            return ! $this->sourcesShareMergeAuthority($existingSource, $incomingSource);
        }

        return $incomingSource->isHumanProvided()
            && $existingSource === ChurchServiceItemSource::OpenLp;
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
     * @param  array<int, array{existing_item:ChurchServiceItem,incoming_item:array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>},desired_position:int}>  $pendingUpdates
     */
    private function stagePendingMatchedItems(ChurchService $churchService, array $pendingUpdates): void
    {
        if ($pendingUpdates === []) {
            return;
        }

        $maxPosition = (int) ChurchServiceItem::query()->withTrashed()
            ->where('church_service_id', $churchService->id)
            ->max('position');

        foreach (array_values($pendingUpdates) as $index => $pendingUpdate) {
            $temporaryPosition = $maxPosition + $index + 1;
            $existingItem = $pendingUpdate['existing_item'];

            ChurchServiceItem::query()->withTrashed()
                ->whereKey($existingItem->id)
                ->update(['position' => $temporaryPosition]);

            $existingItem->position = $temporaryPosition;
            $existingItem->syncOriginalAttribute('position');
        }
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

    /**
     * @param  array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,song_id:int|null,metadata:?array<string,mixed>}  $incomingItem
     * @return array<int, array<string, mixed>>
     */
    private function conflictsForMatchedItem(
        ChurchServiceItem $existingItem,
        array $incomingItem,
        ChurchServiceItemSource $incomingSource,
        bool $preserveExistingSongIdentity
    ): array {
        if (! $preserveExistingSongIdentity) {
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
