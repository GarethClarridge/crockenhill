<?php namespace Crockenhill\Http\Controllers;

use League\CommonMark\CommonMarkConverter;

class PageController extends Controller {

	public function showPage()
	{
		return view('page');
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

    return view('pages.create');
  }

  public function store()
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    $page = new \Crockenhill\Page;
    $page->heading = \Input::get('heading');
    $page->slug = \Illuminate\Support\Str::slug(\Input::get('heading'));
    $page->area = \Input::get('area');
    $page->body = \Input::get('body');
    $page->description = \Input::get('description');
    $page->save();

    if (\Input::file('image')) {
      // Make large image for article
      \Image::make(\Input::file('image')
        ->getRealPath())
        // resize the image to a width of 300 and constrain aspect ratio (auto height)
        ->resize(2000)
        ->save('images/headings/large/'.$page->slug.'.jpg');

      // Make smaller image for aside
      \Image::make(\Input::file('image')
        ->getRealPath())
        // resize the image to a width of 300 and constrain aspect ratio (auto height)
        ->resize(300)
        ->save('images/headings/small/'.$page->slug.'.jpg');
    };

    return redirect('/members/pages')->with('message', $page->heading.' successfully created!');
  }

  public function edit($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

		session(['backUrl' => url()->previous()]);

    return view('pages.edit')->with('page', \Crockenhill\Page::where('slug', $slug)->first());
  }

  public function update($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

		//Convert markdown
		$converter = new CommonMarkConverter;
		$markdown = \Input::get('markdown');
		$html = $converter->convertToHtml($markdown);

    $page = \Crockenhill\Page::where('slug', $slug)->first();
    $page->heading = \Input::get('heading');
    $page->slug = \Illuminate\Support\Str::slug(\Input::get('heading'));
		$page->description = \Input::get('description');
    $page->area = \Input::get('area');
		$page->markdown = $markdown;
    $page->body = trim($html);
    $page->save();

		$backUrl = session('backUrl');

    return ($backUrl !== url()->previous())
			? redirect($backUrl)->with('message', $page->heading.' successfully updated!')
			: redirect('/members/pages')->with('message', $page->heading.' successfully updated!');
  }

  public function destroy($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    $page = \Crockenhill\Page::where('slug', $slug)->first();
    $page->delete();

    return redirect('/members/pages')->with('message', $page->heading.' successfully deleted!');
  }

  /*public function changeimage($slug)
  {
      return view('pages.editimage')->with('page', \Crockenhill\Page::where('slug', $slug)->first());
  }

  public function updateimage($slug)
  {
    $page = \Crockenhill\Page::where('slug', $slug)->first();

    // Make large image for article
    Image::make(Input::file('image')
      ->getRealPath())
      // resize the image to a width of 300 and constrain aspect ratio (auto height)
      ->resize(2000, null, true)
      ->save('images/headings/large/'.$page->slug.'.jpg');

    // Make smaller image for aside
    Image::make(Input::file('image')
      ->getRealPath())
      // resize the image to a width of 300 and constrain aspect ratio (auto height)
      ->resize(300, null, true)
      ->save('images/headings/small/'.$page->slug.'.jpg');

    Notification::success('The image was changed.');

    return Redirect::route('/members/pages/changeimage', array('page' => $page->slug));

  }*/
}
