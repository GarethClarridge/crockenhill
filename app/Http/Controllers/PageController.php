<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Support\Facades\View;
use League\CommonMark\CommonMarkConverter;

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
     *
     * @return \Illuminate\View\View
     */
    public function showPage()
    {
        return view('layouts/page');
    }

    /**
     * Display the specified page to the public.
     *
     * @param  string  $area  The area of the page.
     * @param  string  $slug  The slug of the page.
     * @param  \League\CommonMark\CommonMarkConverter  $converter  Service to convert markdown to HTML.
     * @return \Illuminate\Contracts\View\View Returns the view for displaying the page.
     */
    public function show(string $area, string $slug, CommonMarkConverter $converter)
    {
        $page = Page::where('slug', $slug)->where('area', $area)->first();

        if (! $page) {
            abort(404, 'Page not found');
        }

        $html = $converter->convert($page->markdown ?? '');

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
