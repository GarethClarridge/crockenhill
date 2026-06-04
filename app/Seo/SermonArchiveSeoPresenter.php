<?php

declare(strict_types=1);

namespace App\Seo;

use App\Models\Preacher;
use App\Services\PreacherListCache;

class SermonArchiveSeoPresenter
{
    /**
     * @var array<int, ?string>
     */
    private array $memoizedPreacherNames = [];

    public function __construct(
        private readonly PreacherListCache $preacherListRepository,
    ) {}

    /**
     * Generate SEO title based on filters.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function title(array $filters, int $page = 1): string
    {
        $base = 'Sermons';

        if (array_filter($filters)) {
            $parts = [];

            if ($filters['book']) {
                $parts[] = $filters['chapter'] ? "{$filters['book']} {$filters['chapter']}" : $filters['book'];
            }

            if ($filters['preacherId']) {
                $preacherName = $this->resolvePreacherName($filters['preacherId']);
                if ($preacherName) {
                    $parts[] = $preacherName;
                }
            }

            if ($filters['series']) {
                $parts[] = $filters['series'];
            }

            $base = implode(' | ', $parts).' | Sermons';
        }

        if ($page > 1) {
            return "{$base} (Page {$page})";
        }

        return $base;
    }

    /**
     * Generate SEO description based on filters.
     *
     * @param  array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}  $filters
     */
    public function description(array $filters, int $page = 1): string
    {
        if (! array_filter($filters)) {
            $desc = 'Explore the sermon archive at Crockenhill Baptist Church. Watch or listen to Bible teaching from our Sunday services, filtered by scripture, preacher, or series.';
        } else {
            $parts = [];

            if ($filters['book']) {
                $scripture = $filters['chapter'] ? "{$filters['book']} {$filters['chapter']}" : $filters['book'];
                $parts[] = "on {$scripture}";
            }

            if ($filters['preacherId']) {
                $preacherName = $this->resolvePreacherName($filters['preacherId']);
                if ($preacherName) {
                    $parts[] = "by {$preacherName}";
                }
            }

            if ($filters['series']) {
                $parts[] = "in the '{$filters['series']}' series";
            }

            $desc = 'Watch or listen to Bible-based sermons '.implode(' ', $parts).' from Crockenhill Baptist Church. Explore recent teaching from our morning and evening services.';
        }

        if ($page > 1) {
            return "{$desc} - Page {$page}";
        }

        return $desc;
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

    /**
     * Resolve a preacher name from ID, utilizing identity-based request-level memoization
     * and the cached public preacher collection to avoid redundant DB queries.
     *
     * Performance Optimization: Checks the cached 'public_preacher_list' first (which
     * is already loaded in most archive requests) before falling back to a direct
     * database find() for inactive/legacy preachers.
     */
    private function resolvePreacherName(int $preacherId): ?string
    {
        if (isset($this->memoizedPreacherNames[$preacherId])) {
            return $this->memoizedPreacherNames[$preacherId];
        }

        // Try the cached public collection first (it's memoized at the repository layer)
        $preacher = $this->preacherListRepository->forPublicList()->firstWhere('id', $preacherId);

        if ($preacher) {
            return $this->memoizedPreacherNames[$preacherId] = $preacher->name;
        }

        // Fallback to direct lookup for inactive preachers
        $preacher = Preacher::query()->find($preacherId);

        return $this->memoizedPreacherNames[$preacherId] = $preacher?->name;
    }
}
