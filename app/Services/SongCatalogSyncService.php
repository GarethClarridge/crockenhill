<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

class SongCatalogSyncService
{
    public function __construct(
        private readonly OpenLpLyricsParser $lyricsParser,
    ) {}

    /**
     * @return array{
     *     path:string,
     *     dry_run:bool,
     *     source_songs:int,
     *     canonical_groups:int,
     *     duplicate_groups:int,
     *     duplicate_rows:int,
     *     songs_upserted:int,
     *     songs_created:int,
     *     songs_updated:int,
     *     song_authors_upserted:int,
     *     song_books_upserted:int,
     *     song_author_links_synced:int,
     *     song_book_links_synced:int,
     *     groups_with_parse_warnings:int
     * }
     */
    public function sync(?string $path = null, bool $dryRun = false): array
    {
        $resolvedPath = $this->resolvePath($path);
        $sourceData = $this->loadSourceData($resolvedPath);
        $songGroups = $this->groupSongsByCanonicalKey($sourceData['songs']);

        $metrics = [
            'path' => $resolvedPath,
            'dry_run' => $dryRun,
            'source_songs' => count($sourceData['songs']),
            'canonical_groups' => count($songGroups),
            'duplicate_groups' => 0,
            'duplicate_rows' => 0,
            'songs_upserted' => 0,
            'songs_created' => 0,
            'songs_updated' => 0,
            'song_authors_upserted' => 0,
            'song_books_upserted' => 0,
            'song_author_links_synced' => 0,
            'song_book_links_synced' => 0,
            'groups_with_parse_warnings' => 0,
        ];

        foreach ($songGroups as $groupRows) {
            if (count($groupRows) > 1) {
                $metrics['duplicate_groups']++;
                $metrics['duplicate_rows'] += count($groupRows) - 1;
            }
        }

        if ($dryRun) {
            return $metrics;
        }

        DB::transaction(function () use (&$metrics, $sourceData, $songGroups): void {
            [$authorIdMap, $authorUpsertedCount] = $this->upsertSongAuthors($sourceData['authors']);
            $metrics['song_authors_upserted'] = $authorUpsertedCount;

            [$bookIdMap, $bookUpsertedCount] = $this->upsertSongBooks($sourceData['books']);
            $metrics['song_books_upserted'] = $bookUpsertedCount;

            foreach ($songGroups as $canonicalKey => $groupRows) {
                [$song, $created, $parseWarnings] = $this->upsertSongFromGroup($canonicalKey, $groupRows);

                $metrics['songs_upserted']++;

                if ($created) {
                    $metrics['songs_created']++;
                } else {
                    $metrics['songs_updated']++;
                }

                if ($parseWarnings > 0) {
                    $metrics['groups_with_parse_warnings']++;
                }

                $authorPivotRows = $this->buildAuthorPivotRows($song->id, $groupRows, $sourceData['author_links'], $authorIdMap);
                DB::table('song_author_song')->where('song_id', $song->id)->delete();
                if ($authorPivotRows !== []) {
                    DB::table('song_author_song')->insert($authorPivotRows);
                }
                $metrics['song_author_links_synced'] += count($authorPivotRows);

                $bookPivotRows = $this->buildBookPivotRows($song->id, $groupRows, $sourceData['book_links'], $bookIdMap);
                DB::table('song_book_song')->where('song_id', $song->id)->delete();
                if ($bookPivotRows !== []) {
                    DB::table('song_book_song')->insert($bookPivotRows);
                }
                $metrics['song_book_links_synced'] += count($bookPivotRows);
            }
        });

        return $metrics;
    }

    private function resolvePath(?string $path): string
    {
        $configuredPath = $path ?? config('service-tracking.songs.sqlite_path');

        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            throw new RuntimeException('No songs SQLite path configured. Set OPENLP_SONGS_DB_PATH or pass --path.');
        }

        $resolvedPath = trim($configuredPath);

        if (! is_file($resolvedPath)) {
            throw new RuntimeException("Songs SQLite file does not exist at [{$resolvedPath}].");
        }

        if (! is_readable($resolvedPath)) {
            throw new RuntimeException("Songs SQLite file is not readable at [{$resolvedPath}].");
        }

        return $resolvedPath;
    }

    /**
     * @return array{
     *     songs:list<array<string,mixed>>,
     *     authors:list<array<string,mixed>>,
     *     author_links:list<array<string,mixed>>,
     *     books:list<array<string,mixed>>,
     *     book_links:list<array<string,mixed>>
     * }
     */
    private function loadSourceData(string $path): array
    {
        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to open songs SQLite database: '.$exception->getMessage(), previous: $exception);
        }

        $requiredTables = [
            'songs',
            'authors',
            'authors_songs',
            'song_books',
            'songs_songbooks',
        ];

        $existingTables = $this->fetchTableNames($pdo);
        foreach ($requiredTables as $requiredTable) {
            if (! in_array($requiredTable, $existingTables, true)) {
                throw new RuntimeException("Songs SQLite database is missing required table [{$requiredTable}].");
            }
        }

        return [
            'songs' => $this->fetchAll($pdo, 'SELECT id, title, alternate_title, lyrics, verse_order, copyright, comments, ccli_number, search_title, last_modified FROM songs'),
            'authors' => $this->fetchAll($pdo, 'SELECT id, display_name, first_name, last_name FROM authors'),
            'author_links' => $this->fetchAll($pdo, 'SELECT song_id, author_id, author_type FROM authors_songs'),
            'books' => $this->fetchAll($pdo, 'SELECT id, name, publisher FROM song_books'),
            'book_links' => $this->fetchAll($pdo, 'SELECT song_id, songbook_id, entry FROM songs_songbooks'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string, list<array<string,mixed>>>
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

            if (! array_key_exists($canonicalKey, $groups)) {
                $groups[$canonicalKey] = [];
            }

            $groups[$canonicalKey][] = $row;
        }

        return $groups;
    }

    /**
     * @param  list<array<string,mixed>>  $authorRows
     * @return array{0: array<int, int>, 1: int}
     */
    private function upsertSongAuthors(array $authorRows): array
    {
        $payloads = [];

        foreach ($authorRows as $authorRow) {
            $displayName = $this->stringOrNull($authorRow['display_name'] ?? null);
            if ($displayName === null) {
                continue;
            }

            $payloads[] = [
                'display_name' => $displayName,
                'first_name' => $this->stringOrNull($authorRow['first_name'] ?? null),
                'last_name' => $this->stringOrNull($authorRow['last_name'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($payloads !== []) {
            SongAuthor::query()->upsert(
                $payloads,
                ['display_name'],
                ['first_name', 'last_name', 'updated_at']
            );
        }

        /** @var array<string, int> $lookup */
        $lookup = SongAuthor::query()
            ->pluck('id', 'display_name')
            ->mapWithKeys(static fn ($id, $displayName): array => [(string) $displayName => (int) $id])
            ->all();

        $sourceAuthorIdToLocalId = [];

        foreach ($authorRows as $authorRow) {
            $displayName = $this->stringOrNull($authorRow['display_name'] ?? null);
            $sourceAuthorId = $this->intOrNull($authorRow['id'] ?? null);

            if ($displayName === null || $sourceAuthorId === null) {
                continue;
            }

            $localId = $lookup[$displayName] ?? null;
            if ($localId !== null) {
                $sourceAuthorIdToLocalId[$sourceAuthorId] = $localId;
            }
        }

        return [$sourceAuthorIdToLocalId, count($payloads)];
    }

    /**
     * @param  list<array<string,mixed>>  $bookRows
     * @return array{0: array<int, int>, 1: int}
     */
    private function upsertSongBooks(array $bookRows): array
    {
        $payloads = [];

        foreach ($bookRows as $bookRow) {
            $sourceBookId = $this->intOrNull($bookRow['id'] ?? null);
            $bookName = $this->stringOrNull($bookRow['name'] ?? null);

            if ($sourceBookId === null || $bookName === null) {
                continue;
            }

            $payloads[] = [
                'source_book_id' => $sourceBookId,
                'name' => $bookName,
                'publisher' => $this->stringOrNull($bookRow['publisher'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($payloads !== []) {
            SongBook::query()->upsert(
                $payloads,
                ['source_book_id'],
                ['name', 'publisher', 'updated_at']
            );
        }

        /** @var array<int, int> $lookup */
        $lookup = SongBook::query()
            ->pluck('id', 'source_book_id')
            ->mapWithKeys(static fn ($id, $sourceBookId): array => [(int) $sourceBookId => (int) $id])
            ->all();

        return [$lookup, count($payloads)];
    }

    /**
     * @param  list<array<string,mixed>>  $groupRows
     * @return array{0: Song, 1: bool, 2: int}
     */
    private function upsertSongFromGroup(string $canonicalKey, array $groupRows): array
    {
        $representative = $this->selectRepresentativeSong($groupRows);

        $sourceSongIds = array_values(array_filter(
            array_map(fn (array $row): ?int => $this->intOrNull($row['id'] ?? null), $groupRows),
            static fn (?int $songId): bool => $songId !== null
        ));
        sort($sourceSongIds);

        $parsedLyrics = $this->lyricsParser->parse((string) ($representative['lyrics'] ?? ''));
        $warnings = $parsedLyrics['warnings'];

        $importMetadata = [
            'source_song_ids' => $sourceSongIds,
            'source_representative_song_id' => $this->intOrNull($representative['id'] ?? null),
            'source_last_modified' => $this->stringOrNull($representative['last_modified'] ?? null),
            'duplicate_count' => max(0, count($sourceSongIds) - 1),
        ];

        if ($warnings !== []) {
            $importMetadata['lyrics_parse_warnings'] = $warnings;
        }

        $attributes = [
            'canonical_key' => $canonicalKey,
            'title' => $this->stringOrNull($representative['title'] ?? null) ?? 'Untitled',
            'alternate_title' => $this->stringOrNull($representative['alternate_title'] ?? null),
            'lyrics_xml' => (string) ($representative['lyrics'] ?? ''),
            'lyrics_plain' => $parsedLyrics['lyrics_plain'],
            'verse_order' => $this->stringOrNull($representative['verse_order'] ?? null),
            'copyright' => $this->stringOrNull($representative['copyright'] ?? null),
            'comments' => $this->stringOrNull($representative['comments'] ?? null),
            'ccli_number' => $this->stringOrNull($representative['ccli_number'] ?? null),
            'import_metadata' => $importMetadata,
        ];

        $song = Song::withTrashed()
            ->where('canonical_key', $canonicalKey)
            ->first();

        $created = false;

        if ($song instanceof Song) {
            $song->fill($attributes);
            $song->save();

            if ($song->trashed()) {
                $song->restore();
            }
        } else {
            $song = Song::query()->create($attributes);
            $created = true;
        }

        return [$song, $created, count($warnings)];
    }

    /**
     * @param  list<array<string,mixed>>  $groupRows
     * @return array<string,mixed>
     */
    private function selectRepresentativeSong(array $groupRows): array
    {
        usort($groupRows, function (array $left, array $right): int {
            $leftTimestamp = $this->parseTimestamp($left['last_modified'] ?? null);
            $rightTimestamp = $this->parseTimestamp($right['last_modified'] ?? null);

            if ($leftTimestamp === $rightTimestamp) {
                $leftId = $this->intOrNull($left['id'] ?? null) ?? 0;
                $rightId = $this->intOrNull($right['id'] ?? null) ?? 0;

                return $rightId <=> $leftId;
            }

            return $rightTimestamp <=> $leftTimestamp;
        });

        return $groupRows[0];
    }

    /**
     * @param  list<array<string,mixed>>  $groupRows
     * @param  list<array<string,mixed>>  $authorLinks
     * @param  array<int, int>  $authorIdMap
     * @return list<array{song_id:int,song_author_id:int,author_type:string}>
     */
    private function buildAuthorPivotRows(int $songId, array $groupRows, array $authorLinks, array $authorIdMap): array
    {
        $sourceSongIds = array_values(array_filter(
            array_map(fn (array $row): ?int => $this->intOrNull($row['id'] ?? null), $groupRows),
            static fn (?int $id): bool => $id !== null
        ));

        $linksBySong = [];
        foreach ($authorLinks as $link) {
            $sourceSongId = $this->intOrNull($link['song_id'] ?? null);
            if ($sourceSongId === null) {
                continue;
            }

            if (! array_key_exists($sourceSongId, $linksBySong)) {
                $linksBySong[$sourceSongId] = [];
            }

            $linksBySong[$sourceSongId][] = $link;
        }

        $rows = [];
        $seen = [];

        foreach ($sourceSongIds as $sourceSongId) {
            $links = $linksBySong[$sourceSongId] ?? [];

            foreach ($links as $link) {
                $sourceAuthorId = $this->intOrNull($link['author_id'] ?? null);
                if ($sourceAuthorId === null) {
                    continue;
                }

                $localAuthorId = $authorIdMap[$sourceAuthorId] ?? null;
                if ($localAuthorId === null) {
                    continue;
                }

                $authorType = $this->stringOrNull($link['author_type'] ?? null) ?? '';
                $dedupeKey = $localAuthorId.'|'.$authorType;

                if (array_key_exists($dedupeKey, $seen)) {
                    continue;
                }

                $rows[] = [
                    'song_id' => $songId,
                    'song_author_id' => $localAuthorId,
                    'author_type' => $authorType,
                ];
                $seen[$dedupeKey] = true;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $groupRows
     * @param  list<array<string,mixed>>  $bookLinks
     * @param  array<int, int>  $bookIdMap
     * @return list<array{song_id:int,song_book_id:int,entry:string}>
     */
    private function buildBookPivotRows(int $songId, array $groupRows, array $bookLinks, array $bookIdMap): array
    {
        $sourceSongIds = array_values(array_filter(
            array_map(fn (array $row): ?int => $this->intOrNull($row['id'] ?? null), $groupRows),
            static fn (?int $id): bool => $id !== null
        ));

        $linksBySong = [];
        foreach ($bookLinks as $link) {
            $sourceSongId = $this->intOrNull($link['song_id'] ?? null);
            if ($sourceSongId === null) {
                continue;
            }

            if (! array_key_exists($sourceSongId, $linksBySong)) {
                $linksBySong[$sourceSongId] = [];
            }

            $linksBySong[$sourceSongId][] = $link;
        }

        $rows = [];
        $seen = [];

        foreach ($sourceSongIds as $sourceSongId) {
            $links = $linksBySong[$sourceSongId] ?? [];

            foreach ($links as $link) {
                $sourceBookId = $this->intOrNull($link['songbook_id'] ?? null);
                if ($sourceBookId === null) {
                    continue;
                }

                $localBookId = $bookIdMap[$sourceBookId] ?? null;
                if ($localBookId === null) {
                    continue;
                }

                $entry = (string) ($link['entry'] ?? '');
                $dedupeKey = $localBookId.'|'.$entry;

                if (array_key_exists($dedupeKey, $seen)) {
                    continue;
                }

                $rows[] = [
                    'song_id' => $songId,
                    'song_book_id' => $localBookId,
                    'entry' => $entry,
                ];
                $seen[$dedupeKey] = true;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function fetchTableNames(PDO $pdo): array
    {
        $rows = $this->fetchAll($pdo, "SELECT name FROM sqlite_master WHERE type='table'");

        return array_values(array_filter(
            array_map(
                fn (array $row): ?string => $this->stringOrNull($row['name'] ?? null),
                $rows
            ),
            static fn (?string $name): bool => $name !== null
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAll(PDO $pdo, string $sql): array
    {
        try {
            $statement = $pdo->query($sql);
            if ($statement === false) {
                return [];
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = array_values($statement->fetchAll(PDO::FETCH_ASSOC));

            return $rows;
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed running source SQLite query: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function parseTimestamp(mixed $value): int
    {
        if (! is_string($value) || trim($value) === '') {
            return 0;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? 0 : $timestamp;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
