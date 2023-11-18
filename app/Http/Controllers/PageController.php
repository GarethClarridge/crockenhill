<?php namespace Crockenhill\Http\Controllers;

use League\CommonMark\CommonMarkConverter;
use Intervention\Image\Image;

class PageController extends Controller {

	public function showPage()
	{
		return view('layouts/page');
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
		$converter = new CommonMarkConverter();
		$markdown = \Request::input('markdown');
		$html = $converter->convert($markdown);

    $page = new \Crockenhill\Page;
    $page->heading = \Request::input('heading');
    $page->slug = \Illuminate\Support\Str::slug(\Request::input('heading'));
    $page->area = \Request::input('area');
		$page->markdown = $markdown;
    $page->body = trim($html);
		$page->description = \Request::input('description');
    $page->save();

    if (\Request::file('heading-image')) {
      // create new image instance
      $image = \Image::make(\Request::file('heading-image')->getRealPath());

      // Make large image for article
      $image->resize(2000, null, function ($constraint) {
        $constraint->aspectRatio();
        $constraint->upsize();
      })
        ->save('images/headings/large/'.$slug.'.jpg');

      // Make smaller image for aside
      $image->resize(300, null, function ($constraint) {
        $constraint->aspectRatio();
        $constraint->upsize();
        })
        ->save('images/headings/small/'.$slug.'.jpg');
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
    $headingpicture = '/images/headings/large/'.$slug.'.jpg';

    return view('pages.edit', array(
	    'page' 		        => $page,
			'heading'         => $heading,
      'headingpicture'  => $headingpicture
		));
  }

  public function update($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

		//Convert markdown
		$converter = new CommonMarkConverter();
		$markdown = \Request::input('markdown');
		$html = $converter->convert($markdown);

    if (\Request::file('heading-image')) {
      // create new image instance
      $image = \Image::make(\Request::file('heading-image')->getRealPath());

      // Make large image for article
      $image->resize(2000, null, function ($constraint) {
        $constraint->aspectRatio();
        $constraint->upsize();
      })
        ->save('images/headings/large/'.$slug.'.jpg');

      // Make smaller image for aside
      $image->resize(300, null, function ($constraint) {
        $constraint->aspectRatio();
        $constraint->upsize();
        })
        ->save('images/headings/small/'.$slug.'.jpg');
    }

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
