<?php namespace Crockenhill\Http\Controllers;

class PageController extends Controller {

	public function showPage($area, $slug = NULL)
	{
		// Slug defaults to null - then gets set to $area
		if ($slug === NULL) {
			$slug = $area;
		}

    if ($page = \Crockenhill\Page::where('slug', $slug)->first()) {

		  if ($area != $slug) {
		  	$areapage = \Crockenhill\Page::where('slug', $area)->first();
		  	$breadcrumbs 	= '<li><a href="/'.$area.'">'.$areapage->heading.'</a></li>
													<li class="active">'.$page->heading.'</li>';
		  } else {
		  	$breadcrumbs 	= '<li class="active">'.$page->heading.'</li>';
			}
	    $description 	= '<meta name="description" content="'.$page->description.'">';
	    $edit_url 				= 'members/pages/'.$slug;

			return view('page', array(
		    'slug'          => $page->slug,
		    'heading'       => $page->heading,
		    'description'   => $description,
		    'area'					=> $page->area,
		    'breadcrumbs'   => $breadcrumbs,
		    'edit_url'					=> $edit_url,
		    'content'       => htmlspecialchars_decode($page->body),
			));
		} else {
			\App::abort(404);
		};
	}

	public function index()
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    $page = \Crockenhill\Page::where('slug', 'pages')->first();
    $areapage = \Crockenhill\Page::where('slug', 'members')->first();
    $breadcrumbs = '<li>'.link_to($page['area'], $areapage->heading).'&nbsp</li><li class="active">'.$page->heading.'</li>';
    $description = '<meta name="description" content="'.$page->description.'">';

    return view('pages.index', array(
	    'slug'          => $page->slug,
	    'heading'       => $page->heading,
	    'description'   => $description,
	    'area'          => $page->area,
	    'breadcrumbs'   => $breadcrumbs,
	    'content'       => htmlspecialchars_decode($page->body),
	    'pages'         => \Crockenhill\Page::all()
    ));
  }

  public function create()
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    return view('pages.create', array(
      'heading'       => 'Create a new page',
      'description'   => '<meta name="description" content="Create a new website page.">',
      'breadcrumbs'   => '<li><a href="/members">Members</a></li><li><a href="/members/pages">Pages</a></li><li class="active">Create</li>',
      'content'       => '',
    ));
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

    return redirect('/members/pages')->with('message', 'New page successfully created!');
  }

  public function edit($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    return view('pages.edit')->with('page', \Crockenhill\Page::where('slug', $slug)->first());
  }

  public function update($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    $page = \Crockenhill\Page::where('slug', $slug)->first();
    $page->heading = \Input::get('heading');
    $page->slug = \Illuminate\Support\Str::slug(\Input::get('heading'));
    $page->area = \Input::get('area');
    $page->body = trim(\Input::get('body'));
    $page->description = \Input::get('description');
    $page->save();

    return redirect('/members/pages')->with('message', 'Page successfully updated!');
  }

  public function destroy($slug)
  {
    if (\Gate::denies('edit-pages')) {
      abort(403);
    }

    $page = \Crockenhill\Page::where('slug', $slug)->first();
    $page->delete();

    return redirect('/members/pages')->with('message', 'Page successfully deleted!');
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
