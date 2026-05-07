<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Preacher;

class SermonArchiveSeoPresenter
{
    /**
     * @var array<int, ?string>
     */
    private array $memoizedPreacherNames = [];

    /**
     * Generate SEO title based on filters.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function title(array $filters): string
    {
        if (! array_filter($filters)) {
            return 'Sermons';
        }

        $parts = [];

        if ($filters['book']) {
            $parts[] = $filters['chapter'] ? "{$filters['book']} {$filters['chapter']}" : $filters['book'];
        }

        if ($filters['preacherId']) {
            $preacherName = $this->preacherName((int) $filters['preacherId']);
            if ($preacherName) {
                $parts[] = $preacherName;
            }
        }

        if ($filters['series']) {
            $parts[] = $filters['series'];
        }

        return implode(' | ', $parts).' | Sermons';
    }

    /**
     * Generate SEO description based on filters.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function description(array $filters): string
    {
        if (! array_filter($filters)) {
            return 'Browse sermons from Crockenhill Baptist Church and filter by scripture, preacher, or series.';
        }

        $parts = [];

        if ($filters['book']) {
            $scripture = $filters['chapter'] ? "{$filters['book']} {$filters['chapter']}" : $filters['book'];
            $parts[] = "on {$scripture}";
        }

        if ($filters['preacherId']) {
            $preacherName = $this->preacherName((int) $filters['preacherId']);
            if ($preacherName) {
                $parts[] = "by {$preacherName}";
            }
        }

        if ($filters['series']) {
            $parts[] = "in the {$filters['series']} series";
        }

        return 'Browse sermons from Crockenhill Baptist Church '.implode(' ', $parts).'.';
    }

    /**
     * Get the preacher name for the given ID, using request-level memoization
     * and the cached public preacher list to avoid redundant DB lookups.
     */
    private function preacherName(int $preacherId): ?string
    {
        if (isset($this->memoizedPreacherNames[$preacherId])) {
            return $this->memoizedPreacherNames[$preacherId];
        }

        $preacher = Preacher::getForPublicList()->firstWhere('id', $preacherId);

        return $this->memoizedPreacherNames[$preacherId] = $preacher?->name;
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
