<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\OpenLpSongSourceData;
use App\Data\SongCatalogSyncReport;
use App\Models\Song;
use App\Services\Song\Sync\LegacySongReconciler;
use App\Services\Song\Sync\OpenLpRowValue;
use App\Services\Song\Sync\OpenLpSongSourceReader;
use App\Services\Song\Sync\SongAuthorBookSyncer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Coordinates the OpenLP songs SQLite → canonical catalogue sync.
 *
 * Collaborators own the distinct responsibilities: OpenLpSongSourceReader
 * reads the SQLite source, LegacySongReconciler matches pre-import rows,
 * and SongAuthorBookSyncer handles authors/books and pivot links. This
 * service groups source rows into canonical songs, upserts them, and
 * assembles the run report. Dry-run and real mode share the same pipeline;
 * dry-run substitutes preview (identity) ID maps and skips all writes.
 */
class SongCatalogSyncService
{
    public function __construct(
        private readonly OpenLpLyricsParser $lyricsParser,
        private readonly OpenLpSongSourceReader $sourceReader,
        private readonly LegacySongReconciler $reconciler,
        private readonly SongAuthorBookSyncer $syncer,
    ) {}

    public function sync(?string $path = null, bool $dryRun = false): SongCatalogSyncReport
    {
        [$resolvedPath, $sourceData] = $this->sourceReader->read($path);

        $songGroups = $this->groupSongsByCanonicalKey($sourceData->songs);
        $existingSongs = $this->existingSongsByCanonicalKey(array_keys($songGroups));
        $legacyReconciliationMap = $this->reconciler->buildReconciliationMap($songGroups, $existingSongs);

        $report = $this->initializeReport($resolvedPath, $dryRun, $sourceData, $songGroups);

        if ($dryRun) {
            return $this->previewSync($report, $sourceData, $songGroups, $existingSongs, $legacyReconciliationMap);
        }

        return $this->applySync($report, $sourceData, $songGroups, $legacyReconciliationMap);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $songGroups
     */
    private function initializeReport(string $path, bool $dryRun, OpenLpSongSourceData $sourceData, array $songGroups): SongCatalogSyncReport
    {
        $report = new SongCatalogSyncReport(
            path: $path,
            dryRun: $dryRun,
            sourceSongs: count($sourceData->songs),
            canonicalGroups: count($songGroups),
        );

        foreach ($songGroups as $groupRows) {
            if (count($groupRows) > 1) {
                $report->duplicateGroups++;
                $report->duplicateRows += count($groupRows) - 1;
            }
        }

        return $report;
    }

    /**
     * Apply the sync inside one transaction.
     *
     * @param  array<string, list<array<string, mixed>>>  $songGroups
     * @param  array<string, array{song_id: int, deleted: bool}>  $legacyReconciliationMap
     */
    private function applySync(
        SongCatalogSyncReport $report,
        OpenLpSongSourceData $sourceData,
        array $songGroups,
        array $legacyReconciliationMap
    ): SongCatalogSyncReport {
        DB::transaction(function () use ($report, $sourceData, $songGroups, $legacyReconciliationMap): void {
            [$authorIdMap, $authorUpsertedCount] = $this->syncer->upsertAuthors($sourceData->authors);
            $report->songAuthorsUpserted = $authorUpsertedCount;

            [$bookIdMap, $bookUpsertedCount] = $this->syncer->upsertBooks($sourceData->books);
            $report->songBooksUpserted = $bookUpsertedCount;

            $authorLinksBySourceSongId = $this->syncer->groupLinksBySourceSongId($sourceData->authorLinks);
            $bookLinksBySourceSongId = $this->syncer->groupLinksBySourceSongId($sourceData->bookLinks);

            foreach ($songGroups as $canonicalKey => $groupRows) {
                $this->processSongGroup(
                    $canonicalKey,
                    $groupRows,
                    $authorLinksBySourceSongId,
                    $bookLinksBySourceSongId,
                    $authorIdMap,
                    $bookIdMap,
                    $legacyReconciliationMap[$canonicalKey]['song_id'] ?? null,
                    $report,
                );
            }
        });

        return $report;
    }

    /**
     * Walk the same pipeline as applySync without writing anything: preview
     * (identity) ID maps stand in for the post-upsert lookups, so the link
     * counts dedupe on source IDs exactly as the dry-run metrics always have.
     *
     * @param  array<string, list<array<string, mixed>>>  $songGroups
     * @param  array<string, array{song_id: int, deleted: bool}>  $existingSongs
     * @param  array<string, array{song_id: int, deleted: bool}>  $legacyReconciliationMap
     */
    private function previewSync(
        SongCatalogSyncReport $report,
        OpenLpSongSourceData $sourceData,
        array $songGroups,
        array $existingSongs,
        array $legacyReconciliationMap
    ): SongCatalogSyncReport {
        [$authorIdMap, $authorUpsertedCount] = $this->syncer->previewAuthorUpserts($sourceData->authors);
        $report->songAuthorsUpserted = $authorUpsertedCount;

        [$bookIdMap, $bookUpsertedCount] = $this->syncer->previewBookUpserts($sourceData->books);
        $report->songBooksUpserted = $bookUpsertedCount;

        $authorLinksBySourceSongId = $this->syncer->groupLinksBySourceSongId($sourceData->authorLinks);
        $bookLinksBySourceSongId = $this->syncer->groupLinksBySourceSongId($sourceData->bookLinks);

        foreach ($songGroups as $canonicalKey => $groupRows) {
            $report->songsUpserted++;

            $existingSong = $existingSongs[$canonicalKey] ?? $legacyReconciliationMap[$canonicalKey] ?? null;

            if ($existingSong !== null) {
                $report->songsUpdated++;

                if ($existingSong['deleted'] === true) {
                    $report->songsRestored++;
                }
            } else {
                $report->songsCreated++;
            }

            $representative = $this->selectRepresentativeSong($groupRows);
            $parsedLyrics = $this->lyricsParser->parse(
                (string) ($representative['lyrics'] ?? ''),
                OpenLpRowValue::stringOrNull($representative['verse_order'] ?? null),
            );
            if ($parsedLyrics['warnings'] !== []) {
                $report->groupsWithParseWarnings++;
            }

            $sourceSongIds = OpenLpRowValue::sourceSongIds($groupRows);

            $report->songAuthorLinksSynced += count(
                $this->syncer->authorPivotRows(0, $sourceSongIds, $authorLinksBySourceSongId, $authorIdMap)
            );
            $report->songBookLinksSynced += count(
                $this->syncer->bookPivotRows(0, $sourceSongIds, $bookLinksBySourceSongId, $bookIdMap)
            );
        }

        return $report;
    }

    /**
     * Upsert one canonical song group and replace its author/book links.
     *
     * @param  list<array<string, mixed>>  $groupRows
     * @param  array<int, list<array<string, mixed>>>  $authorLinksBySourceSongId
     * @param  array<int, list<array<string, mixed>>>  $bookLinksBySourceSongId
     * @param  array<int, int>  $authorIdMap
     * @param  array<int, int>  $bookIdMap
     */
    private function processSongGroup(
        string $canonicalKey,
        array $groupRows,
        array $authorLinksBySourceSongId,
        array $bookLinksBySourceSongId,
        array $authorIdMap,
        array $bookIdMap,
        ?int $reconciledSongId,
        SongCatalogSyncReport $report,
    ): void {
        [$song, $created, $restored, $parseWarnings] = $this->upsertSongFromGroup($canonicalKey, $groupRows, $reconciledSongId);

        $report->songsUpserted++;

        if ($created) {
            $report->songsCreated++;
        } else {
            $report->songsUpdated++;
        }

        if ($restored) {
            $report->songsRestored++;
        }

        if ($parseWarnings > 0) {
            $report->groupsWithParseWarnings++;
        }

        $sourceSongIds = OpenLpRowValue::sourceSongIds($groupRows);

        $authorPivotRows = $this->syncer->authorPivotRows($song->id, $sourceSongIds, $authorLinksBySourceSongId, $authorIdMap);
        $this->syncer->replaceAuthorLinks($song->id, $authorPivotRows);
        $report->songAuthorLinksSynced += count($authorPivotRows);

        $bookPivotRows = $this->syncer->bookPivotRows($song->id, $sourceSongIds, $bookLinksBySourceSongId, $bookIdMap);
        $this->syncer->replaceBookLinks($song->id, $bookPivotRows);
        $report->songBookLinksSynced += count($bookPivotRows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupSongsByCanonicalKey(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $searchTitle = is_string($row['search_title'] ?? null) ? $row['search_title'] : '';
            $canonicalKey = Song::canonicalizeKey($searchTitle);

            if ($canonicalKey === '') {
                continue;
            }

            $groups[$canonicalKey][] = $row;
        }

        return $groups;
    }

    /**
     * @param  list<string>  $canonicalKeys
     * @return array<string, array{song_id: int, deleted: bool}>
     */
    private function existingSongsByCanonicalKey(array $canonicalKeys): array
    {
        if ($canonicalKeys === []) {
            return [];
        }

        /** @var array<string, array{song_id: int, deleted: bool}> $songs */
        $songs = Song::query()
            ->withTrashed()
            ->whereIn('canonical_key', $canonicalKeys)
            ->get(['id', 'canonical_key', 'deleted_at'])
            ->mapWithKeys(static fn (Song $song): array => [
                $song->canonical_key => [
                    'song_id' => $song->id,
                    'deleted' => $song->deleted_at !== null,
                ],
            ])
            ->all();

        return $songs;
    }

    /**
     * @param  list<array<string, mixed>>  $groupRows
     * @return array{0: Song, 1: bool, 2: bool, 3: int}
     */
    private function upsertSongFromGroup(string $canonicalKey, array $groupRows, ?int $reconciledSongId = null): array
    {
        $representative = $this->selectRepresentativeSong($groupRows);

        $sourceSongIds = OpenLpRowValue::sourceSongIds($groupRows);
        sort($sourceSongIds);

        $parsedLyrics = $this->lyricsParser->parse(
            (string) ($representative['lyrics'] ?? ''),
            OpenLpRowValue::stringOrNull($representative['verse_order'] ?? null),
        );
        $warnings = $parsedLyrics['warnings'];

        $importMetadata = [
            'source_song_ids' => $sourceSongIds,
            'source_representative_song_id' => OpenLpRowValue::intOrNull($representative['id'] ?? null),
            'source_last_modified' => OpenLpRowValue::stringOrNull($representative['last_modified'] ?? null),
            'duplicate_count' => max(0, count($sourceSongIds) - 1),
        ];

        if ($warnings !== []) {
            $importMetadata['lyrics_parse_warnings'] = $warnings;
        }

        $title = OpenLpRowValue::stringOrNull($representative['title'] ?? null) ?? 'Untitled';
        $lyricsXml = (string) ($representative['lyrics'] ?? '');

        if ($lyricsXml === '') {
            $lyricsXml = '<song><lyrics><verse><lines>No lyrics available.</lines></verse></lyrics></song>';
        }

        $attributes = [
            'canonical_key' => $canonicalKey,
            'first_line_key' => Song::firstLineKeyFromLyrics($parsedLyrics['lyrics_plain']),
            'title' => $title,
            'praise_number' => Song::praiseNumberFromTitle($title),
            'alternate_title' => OpenLpRowValue::stringOrNull($representative['alternate_title'] ?? null),
            'lyrics_xml' => $lyricsXml,
            'lyrics_plain' => $parsedLyrics['lyrics_plain'],
            'verse_order' => OpenLpRowValue::stringOrNull($representative['verse_order'] ?? null),
            'copyright' => OpenLpRowValue::stringOrNull($representative['copyright'] ?? null),
            'comments' => OpenLpRowValue::stringOrNull($representative['comments'] ?? null),
            'ccli_number' => OpenLpRowValue::stringOrNull($representative['ccli_number'] ?? null),
            'import_metadata' => $importMetadata,
        ];

        $song = null;

        if ($reconciledSongId !== null) {
            $song = Song::query()->withTrashed()->find($reconciledSongId);
        }

        if (! $song instanceof Song) {
            $song = Song::query()
                ->withTrashed()
                ->where('canonical_key', $canonicalKey)
                ->first();
        }

        $created = false;
        $restored = false;

        if ($song instanceof Song) {
            $song->fill($attributes);

            if ($song->slug === null || $song->slug === '') {
                $song->slug = $this->generateUniqueSlug($title, $song->id);
            }

            $song->save();

            if ($song->trashed()) {
                $song->restore();
                $restored = true;
            }
        } else {
            $attributes['slug'] = $this->generateUniqueSlug($title);
            $song = Song::query()->create($attributes);
            $created = true;
        }

        return [$song, $created, $restored, count($warnings)];
    }

    /**
     * Pick the most recently modified row of a canonical group (highest source
     * ID breaks ties) as the source of truth for the song's attributes.
     *
     * @param  list<array<string, mixed>>  $groupRows
     * @return array<string, mixed>
     */
    private function selectRepresentativeSong(array $groupRows): array
    {
        usort($groupRows, function (array $left, array $right): int {
            $leftTimestamp = OpenLpRowValue::parseTimestamp($left['last_modified'] ?? null);
            $rightTimestamp = OpenLpRowValue::parseTimestamp($right['last_modified'] ?? null);

            if ($leftTimestamp === $rightTimestamp) {
                $leftId = OpenLpRowValue::intOrNull($left['id'] ?? null) ?? 0;
                $rightId = OpenLpRowValue::intOrNull($right['id'] ?? null) ?? 0;

                return $rightId <=> $leftId;
            }

            return $rightTimestamp <=> $leftTimestamp;
        });

        return $groupRows[0];
    }

    private function generateUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'untitled';
        }

        $slug = $base;
        $suffix = 2;

        while (true) {
            $query = Song::query()->withTrashed()->where('slug', $slug);
            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            if (! $query->exists()) {
                break;
            }

            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
