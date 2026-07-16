<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Page;
use App\Services\Public\PageListCache;
use Illuminate\Support\Collection;

class RelatedPagePresenter
{
    public function __construct(
        private readonly PageListCache $pageListCache,
        private readonly PageCardPresenter $pageCardPresenter,
    ) {}

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    public function ordered(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
    ): Collection {
        return $this->presentCollection($this->filteredLinks(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        )->sortBy('slug')->values());
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    public function random(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
        int $limit = 5,
    ): Collection {
        return $this->presentCollection($this->filteredLinks(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        )->shuffle()->take($limit));
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, Page>
     */
    private function filteredLinks(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages,
        array $extraExcludedSlugs,
    ): Collection {
        if ($linkArea === '') {
            return new Collection;
        }

        return $this->pageListCache->getAllLinksForArea($linkArea)
            ->filter(function (Page $page) use ($slugToExclude, $secondSlugToExclude, $excludeAdminPages, $extraExcludedSlugs): bool {
                if ($slugToExclude !== null && $page->slug === $slugToExclude) {
                    return false;
                }

                if ($secondSlugToExclude !== null && $page->slug === $secondSlugToExclude) {
                    return false;
                }

                if ($excludeAdminPages && $page->isAdminOnly()) {
                    return false;
                }

                return ! in_array($page->slug, $extraExcludedSlugs, true);
            });
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    private function presentCollection(Collection $pages): Collection
    {
        return $this->pageCardPresenter->presentCollection($pages);
    }
}
