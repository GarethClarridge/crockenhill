<?php

declare(strict_types=1);

namespace App\Services\Song\Sync;

/**
 * Scalar normalisation for raw OpenLP SQLite row values, shared by the
 * source reader, reconciler, syncer, and sync coordinator.
 */
final class OpenLpRowValue
{
    public static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    public static function parseTimestamp(mixed $value): int
    {
        if (! is_string($value) || trim($value) === '') {
            return 0;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? 0 : $timestamp;
    }

    /**
     * Extract the source song IDs present in a canonical group's rows.
     *
     * @param  list<array<string, mixed>>  $groupRows
     * @return list<int>
     */
    public static function sourceSongIds(array $groupRows): array
    {
        return array_values(array_filter(
            array_map(static fn (array $row): ?int => self::intOrNull($row['id'] ?? null), $groupRows),
            static fn (?int $songId): bool => $songId !== null
        ));
    }
}
