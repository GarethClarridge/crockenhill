<?php

namespace Crockenhill\Http\Controllers;

use App\Http\Requests\StorePageRequest;
use App\Http\Requests\UpdatePageRequest;
use App\Services\PageImageService;
use Crockenhill\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;

class PageController extends Controller
{
    public function __construct(private PageImageService $pageImageService)
    {
    }

    // This method seems generic, leaving as is.
    public function showPage()
  {
    return view('layouts/page');
  }

  /**
   * Display a listing of the resource.
   *
   * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
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
   * Show the form for creating a new resource.
   *
   * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
   */
  public function create()
  {
    if (Gate::denies('edit-pages')) {
      abort(403);
    }
    return View::make('pages.create', ['heading' => 'Create a page']);
  }

  /**
   * Display the specified resource.
   *
   * @param Page $page
   * @param CommonMarkConverter $converter
   * @return \Illuminate\View\View
   */
  public function show(Page $page, CommonMarkConverter $converter)
  {
      $html = $converter->convert($page->markdown);
      // The view 'pages.show' might expect $page->body, ensure consistency
      // or update the view. Assuming $html is what's needed.
      return View::make('pages.show')->with(compact('page', 'html'));
  }

  /**
   * Store a newly created resource in storage.
   *
   * @param StorePageRequest $request
   * @param CommonMarkConverter $converter
   * @return RedirectResponse
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
   * Show the form for editing the specified resource.
   *
   * @param Page $page
   * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
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
   * Update the specified resource in storage.
   *
   * @param UpdatePageRequest $request
   * @param Page $page
   * @param CommonMarkConverter $converter
   * @return RedirectResponse
   */
  public function update(UpdatePageRequest $request, Page $page, CommonMarkConverter $converter): RedirectResponse
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
      ? Redirect::to($backUrl)->with('message', $page->heading . ' successfully updated!')
      : Redirect::to('/church/members/pages')->with('message', $page->heading . ' successfully updated!');
  }

  /**
   * Remove the specified resource from storage.
   *
   * @param Page $page
   * @return RedirectResponse
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
