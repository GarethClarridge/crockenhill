<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\SafeMarkdownRenderer;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\View;

/**
 * Controller for displaying pages to the public.
 *
 * Note: Page CRUD operations are handled by Filament (PageResource).
 * This controller only handles public-facing page display.
 */
class PageController extends Controller
{
    /**
     * Display a generic page layout.
     */
    public function showPage(): ViewContract
    {
        return view('layouts/page');
    }

    /**
     * Display the specified page to the public.
     *
     * @param  string  $area  The area of the page.
     * @param  string  $slug  The slug of the page.
     * @param  \App\Services\SafeMarkdownRenderer  $markdownRenderer  Service to convert markdown to safe HTML.
     */
    public function show(string $area, string $slug, SafeMarkdownRenderer $markdownRenderer): ViewContract
    {
        $page = Page::where('slug', $slug)->where('area', $area)->first();

        if (! $page) {
            abort(404, 'Page not found');
        }

        $html = $markdownRenderer->convert($page->markdown);

        return View::make('layouts/page')->with([
            'page' => $page,
            'html' => $html,
            'content' => $html,
            'heading' => $page->heading,
            'description' => $page->description,
            'area' => $page->area->value,
            'slug' => $page->slug,
            'headingpicture' => $page->heading_image_url,
            'headingpictureMobile' => $page->heading_image_mobile_url,
            'headingpictureTablet' => $page->heading_image_tablet_url,
        ]);
    }
}
