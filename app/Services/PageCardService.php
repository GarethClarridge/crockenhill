<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PageArea;
use App\Models\Page;
use App\Repositories\PageRepository;
use Illuminate\Support\Collection;

class PageCardService
{
    public function __construct(
        private readonly PageRepository $pageRepository
    ) {}

    /**
     * @return Collection<int, Page>
     */
    public function forHome(): Collection
    {
        return $this->communityPages([
            'sunday-evenings',
            'bible-study',
        ]);
    }

    /**
     * @return Collection<int, Page>
     */
    public function forCommunity(): Collection
    {
        return $this->communityPages([
            'coffee-cup',
            'baby-talk',
            'sunday-mornings',
            'family-talk',
            'buzz-club',
            'christianity-explored',
            'bible-study',
            'carols-in-the-chequers',
        ]);
    }

    /**
     * @return Collection<int, Page>
     */
    public function forChurch(): Collection
    {
        return $this->communityPages([
            'sunday-mornings',
            'sunday-evenings',
            'bible-study',
        ]);
    }

    /**
     * @return Collection<int, Page>
     */
    public function churchLinks(): Collection
    {
        /**
         * Performance Optimization: Use PageRepository to fetch cached area links.
         */
        return $this->pageRepository->getAllLinksForArea(PageArea::CHURCH)
            ->filter(function (Page $page) {
                return $page->slug !== 'privacy-policy'
                    && $page->slug !== 'safeguarding-policy'
                    && $page->admin !== 'yes';
            })
            ->values();
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, Page>
     */
    private function communityPages(array $slugs): Collection
    {
        /**
         * Performance Optimization: Use PageRepository to fetch cached area links.
         */
        return $this->pageRepository->getAllLinksForArea(PageArea::COMMUNITY)
            ->filter(function (Page $page) use ($slugs) {
                return in_array($page->slug, $slugs, true);
            })
            ->values();
    }
}
