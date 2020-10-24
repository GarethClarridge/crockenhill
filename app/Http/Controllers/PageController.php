<?php namespace Crockenhill\Http\Controllers;

use League\CommonMark\CommonMarkConverter;

class PageController extends Controller {

	public function showPage()
	{
		return view('page');
	}

	public function showTopLevelPage()
	{
		return view('top-level-page');
	}

	public function index()
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }
		$pages = \Crockenhill\Page::orderBy('area', 'asc')->get();

    return view('pages.index', array(
	    'pages'         => $pages
    ));
  }

  public function create()
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    return view('pages.create', array(
			'heading' => 'Create a page'
		));
  }

  public function store()
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

		//Convert markdown
		$converter = new CommonMarkConverter;
		$markdown = \Request::input('markdown');
		$html = $converter->convertToHtml($markdown);

    $page = new \Crockenhill\Page;
    $page->heading = \Request::input('heading');
    $page->slug = \Illuminate\Support\Str::slug(\Request::input('heading'));
    $page->area = \Request::input('area');
		$page->markdown = $markdown;
    $page->body = trim($html);
		$page->description = \Request::input('description');
    $page->save();

    if (\Request::file('image')) {
      // Make large image for article
      \Image::make(\Request::file('image')
        ->getRealPath())
        // resize the image to a width of 300 and constrain aspect ratio (auto height)
        ->resize(2000)
        ->save('images/headings/large/'.$page->slug.'.jpg');

      // Make smaller image for aside
      \Image::make(\Request::file('image')
        ->getRealPath())
        // resize the image to a width of 300 and constrain aspect ratio (auto height)
        ->resize(300)
        ->save('images/headings/small/'.$page->slug.'.jpg');
    };

    return redirect('/church/members/pages')->with('message', $page->heading.' successfully created!');
  }

  public function edit($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

		session(['backUrl' => url()->previous()]);

		$page = \Crockenhill\Page::where('slug', $slug)->first();
		$heading = 'Edit page';

    return view('pages.edit', array(
	    'page' 		=> $page,
			'heading' => $heading
		));
  }

  public function update($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

		//Convert markdown
		$converter = new CommonMarkConverter;
		$markdown = \Request::input('markdown');
		$html = $converter->convertToHtml($markdown);

    $page = \Crockenhill\Page::where('slug', $slug)->first();
    $page->heading = \Request::input('heading');
    $page->slug = \Illuminate\Support\Str::slug(\Request::input('heading'));
		$page->description = \Request::input('description');
    $page->area = \Request::input('area');
		$page->markdown = $markdown;
    $page->body = trim($html);
    $page->save();

		$backUrl = session('backUrl');

    return ($backUrl !== url()->previous())
			? redirect($backUrl)->with('message', $page->heading.' successfully updated!')
			: redirect('/church/members/pages')->with('message', $page->heading.' successfully updated!');
  }

  public function destroy($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    $page = \Crockenhill\Page::where('slug', $slug)->first();
    $page->delete();

    return redirect('/church/members/pages')->with('message', $page->heading.' successfully deleted!');
  }
}
