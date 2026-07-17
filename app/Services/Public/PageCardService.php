<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PageArea;
use App\Models\Page;
use App\Presenters\PageCardPresenter;
use Illuminate\Support\Collection;

class PageCardService
{
    public const HOME_RAIL_CACHE_KEY = 'page_card_rail_home';

    public const COMMUNITY_RAIL_CACHE_KEY = 'page_card_rail_community';

    public const CHURCH_RAIL_CACHE_KEY = 'page_card_rail_church';

    /**
     * @var list<string>
     */
    private const HOME_CARD_SLUGS = [
        'sunday-evenings',
        'bible-study',
    ];

    /**
     * @var list<string>
     */
    private const COMMUNITY_CARD_SLUGS = [
        'coffee-cup',
        'baby-talk',
        'sunday-mornings',
        'family-talk',
        'buzz-club',
        'christianity-explored',
        'bible-study',
        'carols-in-the-chequers',
    ];

    /**
     * @var list<string>
     */
    private const CHURCH_CARD_SLUGS = [
        'sunday-mornings',
        'sunday-evenings',
        'bible-study',
    ];

    public function __construct(
        private readonly PageCardPresenter $pageCardPresenter,
        private readonly PageListCache $pageRepository
    ) {}

    /**
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    public function forHome(): Collection
    {
        return $this->pagesBySlugs(self::HOME_CARD_SLUGS, self::HOME_RAIL_CACHE_KEY);
    }

    /**
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    public function forCommunity(): Collection
    {
        return $this->pagesBySlugs(self::COMMUNITY_CARD_SLUGS, self::COMMUNITY_RAIL_CACHE_KEY);
    }

    /**
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    public function forChurch(): Collection
    {
        return $this->pagesBySlugs(self::CHURCH_CARD_SLUGS, self::CHURCH_RAIL_CACHE_KEY);
    }

    /**
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    public function churchLinks(): Collection
    {
        /**
         * Performance Optimization: Use PageListCache to fetch cached area links.
         */
        $pages = $this->pageRepository->getAllLinksForArea(PageArea::Church)
            ->filter(function (Page $page) {
                return $page->slug !== 'privacy-notice'
                    && $page->slug !== 'privacy-policy'
                    && $page->slug !== 'safeguarding-policy'
                    && ! $page->isAdminOnly();
            })
            ->values();

        return $this->pageCardPresenter->presentCollection($pages);
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    private function pagesBySlugs(array $slugs, string $cacheKey): Collection
    {
        $pages = $this->pageRepository->getLinksBySlugs($slugs, $cacheKey);

        return $this->pageCardPresenter->presentCollection($pages);
    }
}
