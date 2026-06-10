<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * Outcome metrics for one OpenLP song-catalogue sync run.
 *
 * Counters are mutable: the sync coordinator increments them as it walks the
 * canonical song groups, then hands the finished report to callers.
 */
class SongCatalogSyncReport extends Data
{
    public function __construct(
        public string $path,
        public bool $dryRun,
        public int $sourceSongs = 0,
        public int $canonicalGroups = 0,
        public int $duplicateGroups = 0,
        public int $duplicateRows = 0,
        public int $songsUpserted = 0,
        public int $songsCreated = 0,
        public int $songsUpdated = 0,
        public int $songsRestored = 0,
        public int $songAuthorsUpserted = 0,
        public int $songBooksUpserted = 0,
        public int $songAuthorLinksSynced = 0,
        public int $songBookLinksSynced = 0,
        public int $groupsWithParseWarnings = 0,
    ) {}
}
