<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Raw rows read from an OpenLP songs SQLite database.
 *
 * Row arrays keep the source column names; consumers normalise individual
 * values via OpenLpRowValue.
 */
readonly class OpenLpSongSourceData
{
    /**
     * @param  list<array<string, mixed>>  $songs
     * @param  list<array<string, mixed>>  $authors
     * @param  list<array<string, mixed>>  $authorLinks
     * @param  list<array<string, mixed>>  $books
     * @param  list<array<string, mixed>>  $bookLinks
     */
    public function __construct(
        public array $songs,
        public array $authors,
        public array $authorLinks,
        public array $books,
        public array $bookLinks,
    ) {}
}
