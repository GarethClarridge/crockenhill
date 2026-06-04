<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Models\Page;
use App\Presenters\PageImagePresenter;
use Spatie\Sitemap\Tags\Url;

class PageSitemapPresenter
{
    public function __construct(
        private readonly PageImagePresenter $pageImagePresenter,
    ) {}

    /**
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(Page $page): Url|string|array
    {
        if (! is_string($page->route) || $page->route === '') {
            return [];
        }

        $url = Url::create($page->route)
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            ->setPriority(0.7);

        if ($page->updated_at && $page->updated_at->year > 0) {
            $url->setLastModificationDate($page->updated_at);
        }

        $imageUrl = $this->pageImagePresenter->headingImageUrl($page);

        if ($imageUrl !== null) {
            $url->addImage(
                $imageUrl,
                $page->meta_description,
                '',
                $page->heading
            );
        }

        return $url;
    }
}
