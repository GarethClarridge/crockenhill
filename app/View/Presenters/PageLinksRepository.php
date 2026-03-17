<?php

declare(strict_types=1);

namespace App\View\Presenters;

use App\Models\Page;
use App\Repositories\PageRepository;
use Illuminate\Support\Collection;

class PageLinksRepository
{
    public function __construct(
        private readonly PageRepository $pageRepository
    ) {}

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, Page>
     */
    public function orderedLinks(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
    ): Collection {
        if ($linkArea === '') {
            return new Collection;
        }

        return $this->getFilteredLinks(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        )->sortBy('slug')->values();
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, Page>
     */
    public function randomLinks(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
        int $limit = 5,
    ): Collection {
        if ($linkArea === '') {
            return new Collection;
        }

        return $this->getFilteredLinks(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        )->shuffle()->take($limit);
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, Page>
     */
    private function getFilteredLinks(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
    ): Collection {
        /**
         * Performance Optimization: Use PageRepository to fetch cached area links.
         * Shuffling and filtering are performed in PHP on the cached collection
         * to avoid database I/O on every request, which is efficient for
         * small area-specific datasets.
         */
        return $this->pageRepository->getAllLinksForArea($linkArea)
            ->filter(function (Page $page) use ($slugToExclude, $secondSlugToExclude, $excludeAdminPages, $extraExcludedSlugs) {
                if ($slugToExclude !== null && $page->slug === $slugToExclude) {
                    return false;
                }

                if ($secondSlugToExclude !== null && $page->slug === $secondSlugToExclude) {
                    return false;
                }

                if ($excludeAdminPages && $page->admin === 'yes') {
                    return false;
                }

                if (in_array($page->slug, $extraExcludedSlugs, true)) {
                    return false;
                }

                return true;
            });
    }
}
