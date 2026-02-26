<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;

class PageCardService
{
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
        return Page::query()
            /**
             * Performance Optimization: Limits retrieved columns to required fields for cards,
             * excluding large text fields (like body and markdown) to reduce memory usage.
             */
            ->select(['id', 'slug', 'heading', 'area', 'description', 'admin'])
            ->with('media')
            ->where('area', PageArea::CHURCH->value)
            ->where('slug', '!=', 'privacy-policy')
            ->where('slug', '!=', 'safeguarding-policy')
            ->where('admin', '!=', 'yes')
            ->get();
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, Page>
     */
    private function communityPages(array $slugs): Collection
    {
        return Page::query()
            /**
             * Performance Optimization: Limits retrieved columns to required fields for cards,
             * excluding large text fields (like body and markdown) to reduce memory usage.
             */
            ->select(['id', 'slug', 'heading', 'area', 'description', 'admin'])
            ->with('media')
            ->where('area', PageArea::COMMUNITY->value)
            ->whereIn('slug', $slugs)
            ->get();
    }
}
