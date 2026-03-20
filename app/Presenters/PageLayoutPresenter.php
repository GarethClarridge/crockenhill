<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Page;
use App\Services\SafeMarkdownRenderer;
use Illuminate\Support\Collection;

class PageLayoutPresenter
{
    public function __construct(
        private readonly SafeMarkdownRenderer $markdownRenderer,
        private readonly PageImagePresenter $pageImagePresenter,
    ) {}

    /**
     * @param  Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>|array<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>  $links
     * @return array{
     *     area: string,
     *     content: string,
     *     description: string,
     *     heading: string,
     *     headingpicture: ?string,
     *     headingpictureMobile: ?string,
     *     headingpictureTablet: ?string,
     *     links: Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>,
     *     page: Page,
     *     slug: string
     * }
     */
    public function present(Page $page, string $area, string $slug, Collection|array $links = []): array
    {
        return [
            'area' => $area,
            'content' => $this->renderContent($page),
            'description' => $page->meta_description,
            'heading' => $page->heading,
            'headingpicture' => $this->pageImagePresenter->headingImageUrl($page),
            'headingpictureMobile' => $this->pageImagePresenter->headingImageMobileUrl($page),
            'headingpictureTablet' => $this->pageImagePresenter->headingImageTabletUrl($page),
            'links' => $links instanceof Collection ? $links : collect($links),
            'page' => $page,
            'slug' => $slug,
        ];
    }

    public function renderContent(Page $page): string
    {
        if (filled($page->markdown)) {
            return $this->markdownRenderer->convert($page->markdown);
        }

        return htmlspecialchars_decode($page->body);
    }
}
