<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Page;
use App\View\Presenters\PageLinksRepository;
use Illuminate\Support\Collection;

class RelatedPagePresenter
{
    public function __construct(
        private readonly PageLinksRepository $links,
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
        return $this->presentCollection($this->links->orderedLinks(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        ));
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
        return $this->presentCollection($this->links->randomLinks(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
            limit: $limit,
        ));
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
