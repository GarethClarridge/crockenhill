<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class PreacherResolutionService
{
    /**
     * Resolve a preacher name to a canonical Preacher model.
     * Looks up via PreacherAlias, then by slug. Creates the record on the fly if not found.
     */
    public function resolve(string $name): Preacher
    {
        $normalizedAlias = $this->normalizeAlias($name);

        if ($normalizedAlias === '') {
            $normalizedAlias = 'visiting speaker';
            $name = 'Visiting Speaker';
        }

        $alias = PreacherAlias::where('alias', $normalizedAlias)->first();

        if ($alias) {
            return $alias->preacher;
        }

        $canonicalName = Str::title($this->normalizeWhitespace($name));
        $slug = Str::slug($canonicalName);

        $preacher = $this->findOrCreatePreacher($slug, $canonicalName);
        $alias = $this->findOrCreateAlias($normalizedAlias, $preacher->id);

        if ($alias->preacher_id !== $preacher->id) {
            return $alias->preacher;
        }

        return $preacher;
    }

    public function normalizeAlias(string $value): string
    {
        return strtolower($this->normalizeWhitespace($value));
    }

    public function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function findOrCreatePreacher(string $slug, string $name): Preacher
    {
        try {
            return Preacher::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_active' => true]
            );
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                $existing = Preacher::where('slug', $slug)->first();
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
            return PreacherAlias::firstOrCreate(
                ['alias' => $alias],
                ['preacher_id' => $preacherId]
            );
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                $existing = PreacherAlias::where('alias', $alias)->first();
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
