<?php

declare(strict_types=1);

namespace App\Services\Preacher;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Service for resolving preacher names to canonical model records.
 *
 * Implements a multi-layered lookup strategy using normalized aliases,
 * existing preacher profiles, and slug-based matching. It ensures preacher
 * identity consistency across the application by mapping variant name
 * spellings to a single canonical preacher record, creating new records
 * on-the-fly when no match is found.
 */
class PreacherResolutionService
{
    /**
     * Resolve a preacher name to a canonical Preacher model.
     *
     * Looks up the preacher via the aliases table (normalized), then by
     * name/slug match. Creates the preacher and the alias record on-the-fly
     * if they do not exist.
     *
     * @param  string  $name  The preacher name to resolve
     * @return Preacher The resolved or newly created canonical preacher model
     *
     * @throws QueryException If record creation fails
     */
    public function resolve(string $name): Preacher
    {
        $normalizedAlias = $this->normalizeAlias($name);

        if ($normalizedAlias === '') {
            $normalizedAlias = 'visiting speaker';
            $name = 'Visiting Speaker';
        }

        $alias = PreacherAlias::query()->where('alias', $normalizedAlias)->first();

        if ($alias?->preacher instanceof Preacher) {
            return $alias->preacher;
        }

        $canonicalName = Str::title($this->normalizeWhitespace($name));
        $slug = Str::slug($canonicalName);

        $preacher = $this->findOrCreatePreacher($slug, $canonicalName);
        $alias = $this->findOrCreateAlias($normalizedAlias, $preacher->id);

        if ($alias->preacher_id !== $preacher->id && $alias->preacher instanceof Preacher) {
            return $alias->preacher;
        }

        return $preacher;
    }

    /**
     * Normalize a preacher name for use as a database lookup alias.
     *
     * Converts to lowercase and collapses internal whitespace to a single space.
     *
     * @param  string  $value  The raw preacher name
     * @return string The normalized alias string
     */
    public function normalizeAlias(string $value): string
    {
        return strtolower($this->normalizeWhitespace($value));
    }

    /**
     * Trim and collapse multiple whitespace characters in a string.
     *
     * @param  string  $value  The input string
     * @return string The cleaned string
     */
    public function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function findOrCreatePreacher(string $slug, string $name): Preacher
    {
        try {
            return Preacher::query()->firstOrCreate(
                ['name' => $name],
                ['slug' => $slug, 'is_active' => true]
            );
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                $existing = Preacher::query()->where('name', $name)->first();
                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function findOrCreateAlias(string $alias, int $preacherId): PreacherAlias
    {
        try {
            return PreacherAlias::query()->firstOrCreate(
                ['alias' => $alias],
                ['preacher_id' => $preacherId]
            );
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                $existing = PreacherAlias::query()->where('alias', $alias)->first();
                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        // MySQL: 23000/1062, PostgreSQL: 23505, SQLite: 23000/19
        return $sqlState === '23505'
            || ($sqlState === '23000' && in_array($driverCode, [19, 1062], true));
    }
}
