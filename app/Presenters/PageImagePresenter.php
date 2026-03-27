<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Page;
use App\Services\PageImageCacheService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

    public function headingImageSrcset(Page $page): ?string
    {
        /** @var Media|null $media */
        $media = $page->getFirstMedia('headings');

        if (! $media instanceof Media) {
            return null;
        }

        $srcset = [];

        if ($media->hasGeneratedConversion('mobile')) {
            $srcset[] = $media->getUrl('mobile').' 640w';
        }
        if ($media->hasGeneratedConversion('tablet')) {
            $srcset[] = $media->getUrl('tablet').' 1024w';
        }
        if ($media->hasGeneratedConversion('desktop')) {
            $srcset[] = $media->getUrl('desktop').' 1920w';
        }

        return $srcset !== [] ? implode(', ', $srcset) : null;
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
