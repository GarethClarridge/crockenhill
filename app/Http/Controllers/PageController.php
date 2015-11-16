<?php namespace Crockenhill\Http\Controllers;

class PageController extends BaseController {

	public function showPage($area = 'members', $slug = NULL)
	{
		//Area defaults to members, slug defaults to null

		if ($slug === NULL) {
			$slug = $area;
		}

    if ($page = \Crockenhill\Page::where('slug', $slug)->first()) {
		  
		  $breadcrumbs = '<li class="active">'.$page->heading.'</li>';
	    $links = \Crockenhill\Page::where('area', $area)
	    	->where('slug', '!=', $slug)
	    	->where('slug', '!=', $area)
	    	->where('slug', '!=', 'privacy-policy')
	    	->where('admin', '!=', 'yes')
	    	->orderBy(\DB::raw('RAND()'))
	    	->take(5)
	    	->get();
	    $description = '<meta name="description" content="'.$page->description.'">';
	    
			return view('page', array(
		    'slug'          => $page->slug,
		    'heading'       => $page->heading,		    
		    'description'   => $description,
		    'area'					=> $page->area,
		    'breadcrumbs'   => $breadcrumbs,
		    'content'       => htmlspecialchars_decode($page->body),
		    'links'					=> $links,
			));
		} else {
			\App::abort(404);
		};
	}
}