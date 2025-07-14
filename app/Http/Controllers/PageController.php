<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePageRequest;
use App\Http\Requests\UpdatePageRequest;
use App\Models\Page;
use App\Services\PageImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;

class PageController extends Controller
{
    /**
     * PageController constructor.
     *
     * @param  PageImageService  $pageImageService  Service for handling page image uploads and deletions.
     */
    public function __construct(private PageImageService $pageImageService) {}

    /**
     * Display a generic page layout.
     * Note: This method seems generic and might not be directly related to CRUD of specific page models.
     *
     * @return \Illuminate\View\View
     */
    public function showPage()
    {
        // Debug: Log that showPage was called
        \Log::info('PageController::showPage called', [
            'request_url' => request()->url(),
            'segments' => request()->segments(),
        ]);

        return view('layouts/page');
    }

    /**
     * Display a listing of all pages for administrative purposes.
     * Requires 'edit-pages' authorization.
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse Returns a view with all pages or redirects if not authorized.
     */
    public function index()
    {
        $this->authorize('viewAny', Page::class);
        $pages = Page::orderBy('area', 'asc')->get();

        return View::make('pages.index', ['pages' => $pages]);
    }

    /**
     * Show the form for creating a new page.
     * Requires 'edit-pages' authorization.
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse Returns the page creation view or redirects if not authorized.
     */
    public function create()
    {
        $this->authorize('create', Page::class);

        return View::make('pages.create', ['heading' => 'Create a page']);
    }

    /**
     * Display the specified page to the public.
     * Manually resolves the page by slug and area.
     *
     * @param  string  $area  The area of the page.
     * @param  string  $slug  The slug of the page.
     * @param  \League\CommonMark\CommonMarkConverter  $converter  Service to convert markdown to HTML.
     * @return \Illuminate\Contracts\View\View Returns the view for displaying the page.
     */
    public function show(string $area, string $slug, CommonMarkConverter $converter)
    {
        // Debug: Log the parameters
        \Log::info('PageController::show called', [
            'area' => $area,
            'slug' => $slug,
            'request_url' => request()->url(),
        ]);

        // Debug: Check if pages exist in database
        $allPages = Page::all();
        \Log::info('All pages in database', [
            'count' => $allPages->count(),
            'pages' => $allPages->map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'area' => $p->area->value])->toArray(),
        ]);

        // Debug: Check the specific query
        $query = Page::where('slug', $slug)->where('area', $area);
        \Log::info('Page query', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
        ]);

        // Manually resolve the page by slug and area
        $page = $query->first();

        if (! $page) {
            \Log::error('Page not found', [
                'area' => $area,
                'slug' => $slug,
                'query_sql' => $query->toSql(),
                'query_bindings' => $query->getBindings(),
            ]);
            abort(404, 'Page not found');
        }

        \Log::info('Page found', [
            'page_id' => $page->id,
            'page_slug' => $page->slug,
            'page_area' => $page->area->value,
        ]);

        // The ViewServiceProvider handles most of the page display logic through view composers
        // We just need to pass the page data to the view
        $html = $converter->convert($page->markdown ?? '');

        return View::make('layouts/page')->with([
            'page' => $page,
            'html' => $html,
            'content' => $html, // The view composer expects 'content'
            'heading' => $page->heading,
            'description' => $page->description,
            'area' => $page->area->value,
            'slug' => $page->slug,
        ]);
    }

    /**
     * Store a newly created page in storage.
     * Validated and authorized by StorePageRequest.
     *
     * @param  \App\Http\Requests\StorePageRequest  $request  The validated request for storing a page.
     * @param  \League\CommonMark\CommonMarkConverter  $converter  Service to convert markdown to HTML.
     * @return \Illuminate\Http\RedirectResponse Redirects to the pages index with a success message.
     */
    public function store(StorePageRequest $request, CommonMarkConverter $converter): RedirectResponse
    {
        $validated = $request->validated();

        $html = $converter->convert($validated['markdown']);
        $navigation = ($validated['navigation-radio'] == 'yes');

        $page = new Page;
        $page->heading = $validated['heading'];
        $page->slug = Str::slug($validated['heading']);
        $page->area = $validated['area'];
        $page->markdown = $validated['markdown'];
        $page->body = trim($html);
        $page->description = $validated['description'] ?? null;
        $page->navigation = $navigation;
        $page->save(); // Save once to ensure $page->slug is set for image path

        if ($request->hasFile('heading-image')) {
            $this->pageImageService->handleImageUpload($request->file('heading-image'), $page->slug);
        }

        Session::flash('message', $page->heading.' successfully created!');

        return Redirect::to('/church/members/pages');
    }

    /**
     * Show the form for editing the specified page.
     * Requires 'edit-pages' authorization. Uses route model binding.
     *
     * @param  \App\Models\Page  $page  The Page model instance to edit.
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse Returns the page editing view or redirects if not authorized.
     */
    public function edit(\App\Models\Page $page)
    {
        $this->authorize('update', $page);

        // session(['backUrl' => url()->previous()]); // Consider if this is still needed or handled differently
        Session::put('backUrl', url()->previous());

        $heading = 'Edit page';
        // Assuming images are always jpg as per store method.
        // If image_extension was stored on the model, that could be used.
        $headingpicture = '/images/headings/large/'.$page->slug.'.jpg';
        if (! file_exists(public_path($headingpicture))) {
            $headingpicture = null; // Or a default image
        }

        return View::make('pages.edit', [
            'page' => $page,
            'heading' => $heading,
            'headingpicture' => $headingpicture,
        ]);
    }

    /**
     * Update the specified page in storage.
     * Validated and authorized by UpdatePageRequest. Uses route model binding.
     *
     * @param  \App\Http\Requests\UpdatePageRequest  $request  The validated request for updating a page.
     * @param  \App\Models\Page  $page  The Page model instance to update.
     * @param  \League\CommonMark\CommonMarkConverter  $converter  Service to convert markdown to HTML.
     * @return \Illuminate\Http\RedirectResponse Redirects with a success message.
     */
    public function update(UpdatePageRequest $request, \App\Models\Page $page, CommonMarkConverter $converter): RedirectResponse
    {
        $validated = $request->validated();

        $html = $converter->convert($validated['markdown']);
        $navigation = ($validated['navigation-radio'] == 'yes');

        $page->heading = $validated['heading'];
        $page->description = $validated['description'] ?? null;
        $page->area = $validated['area'];
        $page->navigation = $navigation;
        $page->markdown = $validated['markdown'];
        $page->body = trim($html);

        $newSlug = Str::slug($validated['heading']);
        $oldSlug = $page->slug; // Get the original slug

        if ($oldSlug !== $newSlug) {
            $this->pageImageService->deleteImages($oldSlug);
            $page->slug = $newSlug;
        }

        if ($request->hasFile('heading-image')) {
            // If a new image is uploaded and slug has changed, old images (by oldSlug) are already deleted.
            // If slug hasn't changed, handleImageUpload will overwrite.
            // If new image but old slug had no image, it will create.
            $this->pageImageService->handleImageUpload($request->file('heading-image'), $page->slug);
        }

        $page->save();

        $backUrl = Session::get('backUrl');
        Session::forget('backUrl'); // Clean up session variable

        return ($backUrl && $backUrl !== url()->previous()) // Check if backUrl exists
          ? Redirect::to($backUrl)->with('message', $page->heading.' successfully updated!')
          : Redirect::to('/church/members/pages')->with('message', $page->heading.' successfully updated!');
    }

    /**
     * Remove the specified page from storage.
     * Requires 'edit-pages' authorization. Uses route model binding.
     *
     * @param  \App\Models\Page  $page  The Page model instance to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects to the pages index with a success message.
     */
    public function destroy(\App\Models\Page $page): RedirectResponse
    {
        $this->authorize('delete', $page);

        $this->pageImageService->deleteImages($page->slug);
        $page->delete();

        Session::flash('message', $page->heading.' successfully deleted!');

        return Redirect::to('/church/members/pages');
    }
}
