<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PageArea;
use App\Models\Page;
use App\Presenters\RelatedPagePresenter;
use App\Services\PublicPageReadModelCache;
use App\Services\PublicPageVisibilityGuard;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for displaying pages to the public.
 */
class PageController extends Controller
{
    public function __construct(
        private readonly PublicPageReadModelCache $publicPageReadModelCache,
        private readonly RelatedPagePresenter $relatedPagePresenter,
        private readonly PublicPageVisibilityGuard $publicPageVisibilityGuard,
    ) {}

    /**
     * Display a generic page layout.
     *
     * @param  string  $area  The area of the page.
     */
    public function showPage(string $area): Response
    {
        // Reject segments that do not correspond to a known public area.
        if (PageArea::tryFrom($area) === null) {
            abort(404);
        }

        // Fetch the landing page for this area (where slug equals area)
        $page = Page::query()->where('slug', $area)->where('area', $area)->first();
        if (! $page instanceof Page) {
            if ($area === PageArea::Members->value && ! Auth::check()) {
                // Even if no landing page exists, protect the members area by default
                return redirect()->guest(route('login'));
            }

            abort(404);
        }

        if ($redirect = $this->publicPageVisibilityGuard->enforce($page)) {
            return $redirect;
        }

        return response()->view('layouts/page', $this->publicPageReadModelCache->get($page)->toViewData(
            links: $this->relatedPagePresenter->random(
                linkArea: $area,
                slugToExclude: $page->slug,
                secondSlugToExclude: $page->slug,
                excludeAdminPages: true,
                extraExcludedSlugs: ['privacy-policy'],
            ),
            page: $page,
        ));
    }

    /**
     * Display the specified page to the public.
     *
     * @param  string  $area  The area of the page.
     * @param  string  $slug  The slug of the page.
     */
    public function show(string $area, string $slug): Response
    {
        $page = Page::query()->where('slug', $slug)->where('area', $area)->firstOrFail();

        if ($redirect = $this->publicPageVisibilityGuard->enforce($page)) {
            return $redirect;
        }

        return response()->view($this->resolveView($area, $slug), $this->publicPageReadModelCache->get($page)->toViewData(
            links: $this->relatedPagePresenter->random(
                linkArea: $page->area->value,
                slugToExclude: $page->slug,
                secondSlugToExclude: $page->slug,
                excludeAdminPages: true,
                extraExcludedSlugs: ['privacy-policy'],
            ),
            page: $page,
        ));
    }

    /**
     * Resolves the view for a page, allowing per-slug child views to override
     * the default layout. If a view exists at pages.{area}.{slug} it will be
     * used; otherwise the standard layouts/page template is returned.
     */
    private function resolveView(string $area, string $slug): string
    {
        $override = "pages.{$area}.{$slug}";

        return view()->exists($override) ? $override : 'layouts/page';
    }
}
