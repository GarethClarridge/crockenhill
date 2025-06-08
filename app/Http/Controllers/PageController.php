<?php

namespace Crockenhill\Http\Controllers;

use Crockenhill\Http\Requests\StorePageRequest;
use Crockenhill\Http\Requests\UpdatePageRequest;
use Crockenhill\Services\PageImageService;
use Crockenhill\Page;
use Crockenhill\Meeting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
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
     * @param PageImageService $pageImageService Service for handling page image uploads and deletions.
     */
    public function __construct(private PageImageService $pageImageService)
    {
    }

    /**
     * Display a generic page layout.
     * Note: This method seems generic and might not be directly related to CRUD of specific page models.
     *
     * @return \Illuminate\View\View
     */
    public function showPage()
  {
    return view('layouts/page');
  }

  /**
   * Display a listing of all pages for administrative purposes.
   * Requires 'edit-pages' authorization.
   *
   * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse Returns a view with all pages or redirects if not authorized.
   */
  public function index()
  {
    if (Gate::denies('edit-pages')) {
      abort(403);
    }
    $pages = Page::orderBy('area', 'asc')->get();

    return View::make('pages.index', ['pages' => $pages]);
  }

  /**
   * Show the form for creating a new page.
   * Requires 'edit-pages' authorization.
   *
   * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse Returns the page creation view or redirects if not authorized.
   */
  public function create()
  {
    if (Gate::denies('edit-pages')) {
      abort(403);
    }
    return View::make('pages.create', ['heading' => 'Create a page']);
  }

  /**
   * Display the specified page to the public.
   * Uses route model binding to fetch the Page by its slug.
   *
   * @param \Crockenhill\Page $page The Page model instance.
   * @param \League\CommonMark\CommonMarkConverter $converter Service to convert markdown to HTML.
   * @return \Illuminate\View\View Returns the view for displaying the page.
   */
  public function show(Page $page, CommonMarkConverter $converter)
  {
      $html = $converter->convert($page->markdown);
      // The view 'pages.show' might expect $page->body, ensure consistency
      // or update the view. Assuming $html is what's needed.
      return View::make('pages.show')->with(compact('page', 'html'));
  }

  /**
   * Store a newly created page in storage.
   * Validated and authorized by StorePageRequest.
   *
   * @param \Crockenhill\Http\Requests\StorePageRequest $request The validated request for storing a page.
   * @param \League\CommonMark\CommonMarkConverter $converter Service to convert markdown to HTML.
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

    Session::flash('message', $page->heading . ' successfully created!');
    return Redirect::to('/church/members/pages');
  }

  /**
   * Show the form for editing the specified page.
   * Requires 'edit-pages' authorization. Uses route model binding.
   *
   * @param \Crockenhill\Page $page The Page model instance to edit.
   * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse Returns the page editing view or redirects if not authorized.
   */
  public function edit(Page $page)
  {
    if (Gate::denies('edit-pages')) {
        abort(403);
    }

    // session(['backUrl' => url()->previous()]); // Consider if this is still needed or handled differently
    Session::put('backUrl', url()->previous());


    $heading = 'Edit page';
    // Assuming images are always jpg as per store method.
    // If image_extension was stored on the model, that could be used.
    $headingpicture = '/images/headings/large/' . $page->slug . '.jpg';
    if (!file_exists(public_path($headingpicture))) {
        $headingpicture = null; // Or a default image
    }


    return View::make('pages.edit', [
        'page' => $page,
        'heading' => $heading,
        'headingpicture' => $headingpicture
    ]);
  }

  /**
   * Update the specified page in storage.
   * Validated and authorized by UpdatePageRequest. Uses route model binding.
   *
   * @param \Crockenhill\Http\Requests\UpdatePageRequest $request The validated request for updating a page.
   * @param \Crockenhill\Page $page The Page model instance to update.
   * @param \League\CommonMark\CommonMarkConverter $converter Service to convert markdown to HTML.
   * @return \Illuminate\Http\RedirectResponse Redirects with a success message.
   */
  public function update(UpdatePageRequest $request, Page $page, CommonMarkConverter $converter): RedirectResponse
  {
    $validated = $request->validated();

    $html = $converter->convert($validated['markdown']);
    $navigation = ($validated['navigation-radio'] == 'yes');

    // Store old slug before any changes to the page model instance for it
    $oldSlug = $page->slug;
    $newSlug = Str::slug($validated['heading']); // Determine new slug from new heading

    $page->heading = $validated['heading'];
    $page->description = $validated['description'] ?? null;
    $page->area = $validated['area'];
    $page->navigation = $navigation;
    $page->markdown = $validated['markdown'];
    $page->body = trim($html);

    // Handle image renaming/deletion if slug changes
    if ($oldSlug !== $newSlug) {
        if (!$request->hasFile('heading-image')) {
            // Slug changed, no new image: RENAME images
            $this->pageImageService->renameImages($oldSlug, $newSlug);
        } else {
            // Slug changed, AND new image uploaded: DELETE old images
            // The new image will be saved under the new slug by the subsequent handleImageUpload call
            $this->pageImageService->deleteImages($oldSlug);
        }
        $page->slug = $newSlug; // Update slug on the model
    }

    // Handle new image upload (works for both slug change and no slug change)
    if ($request->hasFile('heading-image')) {
        $this->pageImageService->handleImageUpload($request->file('heading-image'), $page->slug); // Uses $page->slug which is new if changed
    }

    $page->save();

    // Update associated Meeting's slug if page slug changed
    if ($oldSlug !== $newSlug) {
        $meeting = \Crockenhill\Meeting::where('slug', $oldSlug)->first();
        if ($meeting) {
            $meeting->slug = $newSlug;
            // The meeting 'type' cannot be updated to page->heading due to ENUM constraints on meetings.type.
            // Only the slug is updated to maintain association.
            $meeting->save();
        }
    }

    // $oldSlug should have been defined earlier in the method
    // $page->slug now holds the new slug if it was changed

    $message = $page->heading . ' successfully updated!'; // Define message once

    if ($oldSlug !== $page->slug) { // Slug was changed
        $backUrl = Session::get('backUrl');
        Session::forget('backUrl'); // Forget backUrl after getting it

        if ($backUrl) {
            $modifiedBackUrl = str_replace($oldSlug, $page->slug, $backUrl);
            return Redirect::to($modifiedBackUrl)->with('message', $message);
        } else {
            // No backUrl, redirect to the show page with the new slug
            return Redirect::route('pages.show', $page->slug)->with('message', $message);
        }
    } else { // Slug did not change
        $backUrl = Session::get('backUrl');
        Session::forget('backUrl'); // Forget backUrl after getting it

        if ($backUrl && $backUrl !== url()->current()) { // Ensure backUrl is not the current page itself
            return Redirect::to($backUrl)->with('message', $message);
        } else {
            // Default redirect to pages index if no valid backUrl
            return Redirect::to('/church/members/pages')->with('message', $message);
        }
    }
  }

  /**
   * Remove the specified page from storage.
   * Requires 'edit-pages' authorization. Uses route model binding.
   *
   * @param \Crockenhill\Page $page The Page model instance to delete.
   * @return \Illuminate\Http\RedirectResponse Redirects to the pages index with a success message.
   */
  public function destroy(Page $page): RedirectResponse
  {
    if (Gate::denies('edit-pages')) {
        abort(403);
    }

    $this->pageImageService->deleteImages($page->slug);
    $page->delete();

    Session::flash('message', $page->heading . ' successfully deleted!');
    return Redirect::to('/church/members/pages');
  }
}
