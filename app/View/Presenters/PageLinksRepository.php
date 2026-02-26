<?php

declare(strict_types=1);

namespace App\View\Presenters;

use App\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PageLinksRepository
{
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

        return $this->linksQuery(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        )->orderBy('slug', 'asc')->get();
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

        return $this->linksQuery(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        )->inRandomOrder()->take($limit)->get();
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Builder<Page>
     */
    private function linksQuery(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
    ): Builder {
        $query = Page::query()
            /**
             * Performance Optimization: Limits retrieved columns to required fields for related links cards,
             * excluding large text fields (like body and markdown) to reduce memory usage.
             */
            ->select(['id', 'slug', 'heading', 'area', 'description', 'admin'])
            ->with('media')
            ->where('area', $linkArea);

        if ($slugToExclude !== null) {
            $query->where('slug', '!=', $slugToExclude);
        }

        if ($secondSlugToExclude !== null) {
            $query->where('slug', '!=', $secondSlugToExclude);
        }

        foreach ($extraExcludedSlugs as $extraExcludedSlug) {
            $query->where('slug', '!=', $extraExcludedSlug);
        }

        if ($excludeAdminPages) {
            $query->where('admin', '!=', 'yes');
        }

        return $query;
    }
}
