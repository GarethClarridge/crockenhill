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
     * Generate SEO title based on filters.
     *
     * Performance Optimization: Memoizes title generation and utilizes cached
     * preacher listing to avoid redundant DB queries during title assembly.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function title(array $filters): string
    {
        $key = md5(serialize($filters));

        if (isset($this->memoizedTitles[$key])) {
            return $this->memoizedTitles[$key];
        }

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
        $key = md5(serialize($filters));

        if (isset($this->memoizedDescriptions[$key])) {
            return $this->memoizedDescriptions[$key];
        }

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
     * Resolve a preacher name from the cached public listing.
     */
    private function resolvePreacherName(int $preacherId): ?string
    {
        return Preacher::getForPublicList()->firstWhere('id', $preacherId)?->name;
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
