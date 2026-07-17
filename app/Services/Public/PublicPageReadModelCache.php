<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Data\PublicPageReadModel;
use App\Models\Page;
use App\Presenters\PageLayoutPresenter;
use Illuminate\Support\Facades\Cache;

class PublicPageReadModelCache
{
    public function __construct(
        private readonly PageLayoutPresenter $pageLayoutPresenter,
        private readonly PageImageCacheService $pageImageCacheService,
    ) {}

    public function get(Page $page): PublicPageReadModel
    {
        /** @var PublicPageReadModel */
        return Cache::flexible($this->cacheKey($page->id), [300, 86400], function () use ($page): PublicPageReadModel {
            $images = $this->pageImageCacheService->get($page);

            return new PublicPageReadModel(
                area: $page->area->value,
                content: $this->pageLayoutPresenter->renderContent($page),
                description: $page->meta_description,
                heading: $page->heading,
                headingpicture: $images['desktop'],
                headingpictureMobile: $images['mobile'],
                headingpictureTablet: $images['tablet'],
                metaDescription: $page->meta_description,
                slug: $page->slug,
            );
        });
    }

    public function forget(Page|int $page): void
    {
        $pageId = $page instanceof Page ? $page->id : $page;

        Cache::forget($this->cacheKey($pageId));
    }

    private function cacheKey(int $pageId): string
    {
        return "public_page_view_{$pageId}";
    }
}
