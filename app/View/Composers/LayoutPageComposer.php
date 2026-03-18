<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\Page;
use App\Services\SafeMarkdownRenderer;
use App\View\Presenters\AreaLandingPresenter;
use App\View\Presenters\AuthPagePresenter;
use App\View\Presenters\DeepPagePresenter;
use App\View\Presenters\PageLinksRepository;
use App\View\Presenters\PagePresenter;
use App\View\Presenters\SectionPagePresenter;
use App\View\Presenters\SermonDetailPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LayoutPageComposer
{
    public function __construct(
        private readonly SafeMarkdownRenderer $markdownRenderer,
        private readonly PageLinksRepository $links,
        private readonly SermonDetailPresenter $sermonDetailPresenter,
        private readonly AuthPagePresenter $authPagePresenter,
        private readonly DeepPagePresenter $deepPagePresenter,
        private readonly SectionPagePresenter $sectionPagePresenter,
        private readonly AreaLandingPresenter $areaLandingPresenter,
    ) {}

    public function compose(View $view): void
    {
        $page = $view->getData()['page'] ?? null;
        if ($page instanceof Page) {
            $this->composeUsingProvidedPage($view, $page);

            return;
        }

        $presenter = $this->resolvePresenter(request());
        $view->with($presenter->present(request(), $view));
    }

    private function composeUsingProvidedPage(View $view, Page $page): void
    {
        $viewData = $view->getData();

        // Determine content: explicit view data > markdown > legacy body
        if (array_key_exists('content', $viewData)) {
            $content = (string) $viewData['content'];
        } elseif ($page->markdown) {
            $content = $this->markdownRenderer->convert($page->markdown);
        } else {
            $content = htmlspecialchars_decode($page->body);
        }

        // Respect an explicitly provided area (e.g. MeetingController passes 'community'
        // regardless of the linked Page's own area value).
        $area = $viewData['area'] ?? $page->area->value;
        $slug = $page->slug;

        $view->with([
            'description' => $page->meta_description,
            'heading' => $page->heading,
            'content' => $content,
            'headingpicture' => $page->heading_image_url,
            'area' => $area,
            'slug' => $slug,
            'links' => $this->links->randomLinks(
                linkArea: $area,
                slugToExclude: $slug,
                secondSlugToExclude: $slug,
                excludeAdminPages: true,
                extraExcludedSlugs: ['privacy-policy'],
            ),
        ]);
    }

    private function resolvePresenter(Request $request): PagePresenter
    {
        if ($request->segment(2) === 'sermons' && $request->segment(5) !== null) {
            return $this->sermonDetailPresenter;
        }

        if (in_array($request->segment(1), ['login', 'register', 'password'], true)) {
            return $this->authPagePresenter;
        }

        if ($request->segment(3) !== null) {
            return $this->deepPagePresenter;
        }

        if ($request->segment(2) !== null) {
            return $this->sectionPagePresenter;
        }

        return $this->areaLandingPresenter;
    }
}
