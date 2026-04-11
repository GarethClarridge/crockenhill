<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PageArea;
use App\Models\Page;
use App\Presenters\PageCardPresenter;
use App\Repositories\PageRepository;
use Illuminate\Support\Collection;

class PageCardService
{
    public function __construct(
        private readonly PageCardPresenter $pageCardPresenter,
        private readonly PageRepository $pageRepository
    ) {}

    /**
     * @return Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>
     */
    public function forHome(): Collection
    {
        return $this->communityPages([
            'sunday-evenings',
            'bible-study',
        ]);
    }

    /**
     * @return Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>
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
     * @return Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>
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
     * @return Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>
     */
    public function churchLinks(): Collection
    {
        /**
         * Performance Optimization: Use PageRepository to fetch cached area links.
         */
        $pages = $this->pageRepository->getAllLinksForArea(PageArea::Church)
            ->filter(function (Page $page) {
                return $page->slug !== 'privacy-policy'
                    && $page->slug !== 'safeguarding-policy'
                    && $page->admin !== 'yes';
            })
            ->values();

        return $this->pageCardPresenter->presentCollection($pages);
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>
     */
    private function communityPages(array $slugs): Collection
    {
        /**
         * Performance Optimization: Use PageRepository to fetch cached area links.
         */
        $pages = $this->pageRepository->getAllLinksForArea(PageArea::Community)
            ->filter(function (Page $page) use ($slugs) {
                return in_array($page->slug, $slugs, true);
            })
            ->values();

        return $this->pageCardPresenter->presentCollection($pages);
    }
}
