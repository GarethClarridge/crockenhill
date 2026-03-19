<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PageArea;
use App\Models\Page;
use App\Services\SafeMarkdownRenderer;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for displaying pages to the public.
 */
class PageController extends Controller
{
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
        $user = Auth::user();

        if ($page) {
            // Security check: Restricted pages
            if ($page->admin === 'yes' && ($user === null || ! $user->is_admin)) {
                abort(403, 'Unauthorized action.');
            }

            // Security check: Members-only area
            if ($page->area === PageArea::MEMBERS && ! Auth::check()) {
                return redirect()->guest(route('login'));
            }
        } elseif ($area === PageArea::MEMBERS->value && ! Auth::check()) {
            // Even if no landing page exists, protect the members area by default
            return redirect()->guest(route('login'));
        }

        return response()->view('layouts/page', ['page' => $page]);
    }

    /**
     * Display the specified page to the public.
     *
     * @param  string  $area  The area of the page.
     * @param  string  $slug  The slug of the page.
     * @param  \App\Services\SafeMarkdownRenderer  $markdownRenderer  Service to convert markdown to safe HTML.
     */
    public function show(string $area, string $slug, SafeMarkdownRenderer $markdownRenderer): Response
    {
        $page = Page::query()->where('slug', $slug)->where('area', $area)->firstOrFail();
        $user = Auth::user();

        // Security check: Restricted pages
        if ($page->admin === 'yes' && ($user === null || ! $user->is_admin)) {
            abort(403, 'Unauthorized action.');
        }

        // Security check: Members-only area
        if ($page->area === PageArea::MEMBERS && ! Auth::check()) {
            return redirect()->guest(route('login'));
        }

        $html = $page->markdown
            ? $markdownRenderer->convert($page->markdown)
            : htmlspecialchars_decode($page->body);

        return response()->view('layouts/page', [
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
