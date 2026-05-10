<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Preacher;

class SermonArchiveSeoPresenter
{
    /**
     * @var array<string, string>
     */
    private array $memoizedTitles = [];

    /**
     * @var array<string, string>
     */
    private array $memoizedDescriptions = [];

    /**
     * @var array<int, ?string>
     */
    private array $memoizedPreacherNames = [];

    /**
     * Tracks which keys have been computed, allowing null to be a legitimate cached result.
     *
     * @var array<string, true>
     */
    private array $computed = [];

    /**
     * Generate SEO title based on filters.
     *
     * Performance Optimization: Memoizes title generation and utilizes cached
     * preacher listing to avoid redundant DB queries during title assembly.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function title(array $filters): string
    {
        $key = 't_'.md5(serialize($filters));

        if (isset($this->computed[$key])) {
            /** @var string */
            return $this->memoizedTitles[$key];
        }

        $this->computed[$key] = true;

        if (! array_filter($filters)) {
            return $this->memoizedTitles[$key] = 'Sermons';
        }

        $parts = [];

        if ($filters['book']) {
            $parts[] = $filters['chapter'] ? "{$filters['book']} {$filters['chapter']}" : $filters['book'];
        }

        if ($filters['preacherId']) {
            $preacherName = $this->resolvePreacherName((int) $filters['preacherId']);
            if ($preacherName) {
                $parts[] = $preacherName;
            }
        }

        if ($filters['series']) {
            $parts[] = $filters['series'];
        }

        return $this->memoizedTitles[$key] = implode(' | ', $parts).' | Sermons';
    }

    /**
     * Generate SEO description based on filters.
     *
     * Performance Optimization: Memoizes description generation and utilizes
     * cached preacher listing to avoid redundant DB queries during assembly.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function description(array $filters): string
    {
        $key = 'd_'.md5(serialize($filters));

        if (isset($this->computed[$key])) {
            /** @var string */
            return $this->memoizedDescriptions[$key];
        }

        $this->computed[$key] = true;

        if (! array_filter($filters)) {
            return $this->memoizedDescriptions[$key] = 'Browse sermons from Crockenhill Baptist Church and filter by scripture, preacher, or series.';
        }

        $parts = [];

        if ($filters['book']) {
            $scripture = $filters['chapter'] ? "{$filters['book']} {$filters['chapter']}" : $filters['book'];
            $parts[] = "on {$scripture}";
        }

        if ($filters['preacherId']) {
            $preacherName = $this->resolvePreacherName((int) $filters['preacherId']);
            if ($preacherName) {
                $parts[] = "by {$preacherName}";
            }
        }

        if ($filters['series']) {
            $parts[] = "in the {$filters['series']} series";
        }

        return $this->memoizedDescriptions[$key] = 'Browse sermons from Crockenhill Baptist Church '.implode(' ', $parts).'.';
    }

    /**
     * Resolve a preacher name from the cached public listing or database.
     *
     * Performance Optimization: Memoizes preacher name lookups to avoid redundant
     * collection traversal and database queries within a single request.
     */
    private function resolvePreacherName(int $preacherId): ?string
    {
        $compKey = "p_{$preacherId}";

        if (isset($this->computed[$compKey])) {
            /** @var string|null */
            return $this->memoizedPreacherNames[$preacherId];
        }

        $this->computed[$compKey] = true;

        // Try the cached public list first (optimized for the common case)
        $preacherName = Preacher::getForPublicList()->firstWhere('id', $preacherId)?->name;

        // Fallback to database for inactive or non-public preachers
        if ($preacherName === null) {
            $preacherName = Preacher::query()->find($preacherId)?->name;
        }

        return $this->memoizedPreacherNames[$preacherId] = $preacherName;
    }

    /**
     * Clear all internal request-level caches.
     * Useful for long-running processes or testing.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedTitles = [];
        $this->memoizedDescriptions = [];
        $this->memoizedPreacherNames = [];
        $this->computed = [];
    }

    /**
     * Generate canonical URL based on filters and page.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function canonical(array $filters, int $page = 1): string
    {
        $params = array_filter([
            'book' => $filters['book'],
            'chapter' => $filters['chapter'],
            'preacher' => $filters['preacherId'],
            'series' => $filters['series'],
            'page' => $page > 1 ? $page : null,
        ]);

        return route('sermons.index', $params);
    }
}
