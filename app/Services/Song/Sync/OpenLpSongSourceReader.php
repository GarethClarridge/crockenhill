<?php

declare(strict_types=1);

namespace App\Services\Song\Sync;

use App\Data\OpenLpSongSourceData;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Reads the OpenLP songs SQLite database into memory.
 *
 * Owns path resolution/validation and all PDO access; the sync pipeline
 * never touches SQLite directly.
 */
class OpenLpSongSourceReader
{
    private const REQUIRED_TABLES = [
        'songs',
        'authors',
        'authors_songs',
        'song_books',
        'songs_songbooks',
    ];

    /**
     * Resolve the source path (explicit argument or configured default) and
     * load every table the sync needs.
     *
     * @return array{0: string, 1: OpenLpSongSourceData} The resolved path and the loaded rows.
     */
    public function read(?string $path = null): array
    {
        $resolvedPath = $this->resolvePath($path);

        return [$resolvedPath, $this->loadSourceData($resolvedPath)];
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

    private function loadSourceData(string $path): OpenLpSongSourceData
    {
        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to open songs SQLite database: '.$exception->getMessage(), previous: $exception);
        }

        $existingTables = $this->fetchTableNames($pdo);
        foreach (self::REQUIRED_TABLES as $requiredTable) {
            if (! in_array($requiredTable, $existingTables, true)) {
                throw new RuntimeException("Songs SQLite database is missing required table [{$requiredTable}].");
            }
        }

        return new OpenLpSongSourceData(
            songs: $this->fetchAll($pdo, 'SELECT id, title, alternate_title, lyrics, verse_order, copyright, comments, ccli_number, search_title, last_modified FROM songs'),
            authors: $this->fetchAll($pdo, 'SELECT id, display_name, first_name, last_name FROM authors'),
            authorLinks: $this->fetchAll($pdo, 'SELECT song_id, author_id, author_type FROM authors_songs'),
            books: $this->fetchAll($pdo, 'SELECT id, name, publisher FROM song_books'),
            bookLinks: $this->fetchAll($pdo, 'SELECT song_id, songbook_id, entry FROM songs_songbooks'),
        );
    }

    /**
     * @return list<string>
     */
    private function fetchTableNames(PDO $pdo): array
    {
        $rows = $this->fetchAll($pdo, "SELECT name FROM sqlite_master WHERE type='table'");

        return array_values(array_filter(
            array_map(
                static fn (array $row): ?string => OpenLpRowValue::stringOrNull($row['name'] ?? null),
                $rows
            ),
            static fn (?string $name): bool => $name !== null
        ));
    }

    /**
     * @return list<array<string, mixed>>
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
}
