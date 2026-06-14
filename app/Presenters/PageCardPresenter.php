<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Page;
use Illuminate\Support\Collection;

class PageCardPresenter
{
    public function __construct(
        private readonly PageImagePresenter $pageImagePresenter,
    ) {}

    /**
     * @return array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}
     */
    public function present(Page $page): array
    {
        $area = $page->area->value;

        $url = match (true) {
            $area === 'sermons' && $page->slug === 'all' => route('sermons.index'),
            $area === 'sermons' => "/christ/sermons/{$page->slug}",
            default => '/'.$area.'/'.$page->slug,
        };

        return [
            'area' => $area,
            'description' => $page->description,
            'heading' => $page->heading,
            'image_url' => $this->pageImagePresenter->headingImageSmallUrl($page) ?? '/images/headings/small/default.webp',
            'slug' => $page->slug,
            'url' => $url,
        ];
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>
     */
    public function presentCollection(Collection $pages): Collection
    {
        return $pages->map(fn (Page $page): array => $this->present($page))->values();
    }
}
