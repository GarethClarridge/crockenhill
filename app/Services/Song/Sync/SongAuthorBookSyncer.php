<?php

declare(strict_types=1);

namespace App\Services\Song\Sync;

use App\Models\SongAuthor;
use App\Models\SongBook;
use Illuminate\Support\Facades\DB;

/**
 * Syncs song authors, song books, and their pivot links from OpenLP rows.
 *
 * Real and dry-run modes share one pivot-row path: the upsert methods return a
 * source-ID → local-ID map, the preview methods return an identity map over the
 * same valid source IDs, and the pivot builders are agnostic about which they
 * receive. Dedupe therefore happens on local IDs in real mode and on source IDs
 * in preview mode, exactly as the metrics have always counted.
 */
class SongAuthorBookSyncer
{
    /**
     * Upsert authors and map each source author ID to its local SongAuthor ID.
     *
     * @param  list<array<string, mixed>>  $authorRows
     * @return array{0: array<int, int>, 1: int} The source→local ID map and the upserted-payload count.
     */
    public function upsertAuthors(array $authorRows): array
    {
        $payloads = [];

        foreach ($authorRows as $authorRow) {
            $displayName = OpenLpRowValue::stringOrNull($authorRow['display_name'] ?? null);
            if ($displayName === null) {
                continue;
            }

            $payloads[] = [
                'display_name' => $displayName,
                'first_name' => OpenLpRowValue::stringOrNull($authorRow['first_name'] ?? null),
                'last_name' => OpenLpRowValue::stringOrNull($authorRow['last_name'] ?? null),
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
            $displayName = OpenLpRowValue::stringOrNull($authorRow['display_name'] ?? null);
            $sourceAuthorId = OpenLpRowValue::intOrNull($authorRow['id'] ?? null);

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
     * Upsert books and map each source book ID to its local SongBook ID.
     *
     * @param  list<array<string, mixed>>  $bookRows
     * @return array{0: array<int, int>, 1: int} The source→local ID map and the upserted-payload count.
     */
    public function upsertBooks(array $bookRows): array
    {
        $payloads = [];

        foreach ($bookRows as $bookRow) {
            $sourceBookId = OpenLpRowValue::intOrNull($bookRow['id'] ?? null);
            $bookName = OpenLpRowValue::stringOrNull($bookRow['name'] ?? null);

            if ($sourceBookId === null || $bookName === null) {
                continue;
            }

            $payloads[] = [
                'source_book_id' => $sourceBookId,
                'name' => $bookName,
                'publisher' => OpenLpRowValue::stringOrNull($bookRow['publisher'] ?? null),
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
     * Preview the author upsert without writing: an identity map over the valid
     * source author IDs, plus the count an upsert would have processed.
     *
     * @param  list<array<string, mixed>>  $authorRows
     * @return array{0: array<int, int>, 1: int}
     */
    public function previewAuthorUpserts(array $authorRows): array
    {
        $identityMap = [];
        $upsertCount = 0;

        foreach ($authorRows as $authorRow) {
            $displayName = OpenLpRowValue::stringOrNull($authorRow['display_name'] ?? null);
            $sourceAuthorId = OpenLpRowValue::intOrNull($authorRow['id'] ?? null);

            if ($displayName === null || $sourceAuthorId === null) {
                continue;
            }

            $identityMap[$sourceAuthorId] = $sourceAuthorId;
            $upsertCount++;
        }

        return [$identityMap, $upsertCount];
    }

    /**
     * Preview the book upsert without writing: an identity map over the valid
     * source book IDs, plus the count an upsert would have processed.
     *
     * @param  list<array<string, mixed>>  $bookRows
     * @return array{0: array<int, int>, 1: int}
     */
    public function previewBookUpserts(array $bookRows): array
    {
        $identityMap = [];
        $upsertCount = 0;

        foreach ($bookRows as $bookRow) {
            $sourceBookId = OpenLpRowValue::intOrNull($bookRow['id'] ?? null);
            $bookName = OpenLpRowValue::stringOrNull($bookRow['name'] ?? null);

            if ($sourceBookId === null || $bookName === null) {
                continue;
            }

            $identityMap[$sourceBookId] = $sourceBookId;
            $upsertCount++;
        }

        return [$identityMap, $upsertCount];
    }

    /**
     * Group flat link rows by their source song ID.
     *
     * @param  list<array<string, mixed>>  $links
     * @return array<int, list<array<string, mixed>>>
     */
    public function groupLinksBySourceSongId(array $links): array
    {
        $linksBySourceSongId = [];

        foreach ($links as $link) {
            $sourceSongId = OpenLpRowValue::intOrNull($link['song_id'] ?? null);
            if ($sourceSongId === null) {
                continue;
            }

            $linksBySourceSongId[$sourceSongId][] = $link;
        }

        return $linksBySourceSongId;
    }

    /**
     * Build the deduplicated author pivot rows for one canonical song group.
     *
     * @param  list<int>  $sourceSongIds
     * @param  array<int, list<array<string, mixed>>>  $authorLinksBySourceSongId
     * @param  array<int, int>  $authorIdMap
     * @return list<array{song_id: int, song_author_id: int, author_type: string}>
     */
    public function authorPivotRows(int $songId, array $sourceSongIds, array $authorLinksBySourceSongId, array $authorIdMap): array
    {
        $rows = [];
        $seen = [];

        foreach ($sourceSongIds as $sourceSongId) {
            foreach ($authorLinksBySourceSongId[$sourceSongId] ?? [] as $link) {
                $sourceAuthorId = OpenLpRowValue::intOrNull($link['author_id'] ?? null);
                if ($sourceAuthorId === null) {
                    continue;
                }

                $localAuthorId = $authorIdMap[$sourceAuthorId] ?? null;
                if ($localAuthorId === null) {
                    continue;
                }

                $authorType = OpenLpRowValue::stringOrNull($link['author_type'] ?? null) ?? '';
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
     * Build the deduplicated book pivot rows for one canonical song group.
     *
     * @param  list<int>  $sourceSongIds
     * @param  array<int, list<array<string, mixed>>>  $bookLinksBySourceSongId
     * @param  array<int, int>  $bookIdMap
     * @return list<array{song_id: int, song_book_id: int, entry: string}>
     */
    public function bookPivotRows(int $songId, array $sourceSongIds, array $bookLinksBySourceSongId, array $bookIdMap): array
    {
        $rows = [];
        $seen = [];

        foreach ($sourceSongIds as $sourceSongId) {
            foreach ($bookLinksBySourceSongId[$sourceSongId] ?? [] as $link) {
                $sourceBookId = OpenLpRowValue::intOrNull($link['songbook_id'] ?? null);
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
     * Replace a song's author links with the given pivot rows.
     *
     * @param  list<array{song_id: int, song_author_id: int, author_type: string}>  $pivotRows
     */
    public function replaceAuthorLinks(int $songId, array $pivotRows): void
    {
        DB::table('song_author_song')->where('song_id', $songId)->delete();

        if ($pivotRows !== []) {
            DB::table('song_author_song')->insert($pivotRows);
        }
    }

    /**
     * Replace a song's book links with the given pivot rows.
     *
     * @param  list<array{song_id: int, song_book_id: int, entry: string}>  $pivotRows
     */
    public function replaceBookLinks(int $songId, array $pivotRows): void
    {
        DB::table('song_book_song')->where('song_id', $songId)->delete();

        if ($pivotRows !== []) {
            DB::table('song_book_song')->insert($pivotRows);
        }
    }
}
