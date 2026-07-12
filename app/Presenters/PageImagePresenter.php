<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Page;
use App\Services\Public\PageImageCacheService;

class PageImagePresenter
{
    public function __construct(
        private readonly PageImageCacheService $pageImageCacheService,
    ) {}

    public function hasImage(Page $page): bool
    {
        $urls = $this->pageImageCacheService->get($page);

        return $urls['desktop'] !== null
            || $urls['tablet'] !== null
            || $urls['mobile'] !== null
            || $urls['small'] !== null;
    }

    public function headingImageUrl(Page $page): ?string
    {
        return $this->pageImageCacheService->get($page)['desktop'];
    }

    public function headingImageTabletUrl(Page $page): ?string
    {
        return $this->pageImageCacheService->get($page)['tablet'];
    }

    public function headingImageMobileUrl(Page $page): ?string
    {
        return $this->pageImageCacheService->get($page)['mobile'];
    }

    public function headingImageSmallUrl(Page $page): ?string
    {
        return $this->pageImageCacheService->get($page)['small'];
    }
}
