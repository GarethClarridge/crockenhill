<?php

class PageController extends BaseController {

    protected $layout = 'layouts.main';

	public function showPage($slug)
	{
	    $page = Page::where('slug', $slug)->first();
	    $links = Page::where('area', $slug)->where('slug', '!=', $slug)->orderBy(DB::raw('RAND()'))->take(5)->get();
	    
	    $active_breadcrumb = '<li class="active">'.$page->heading.'</li>';
	    $description = '<meta name="description" content="'.$page->description.'">';
	    
		$this->layout->content = View::make('pages.page', array(
		    'slug'          => $page->slug,
		    'heading'       => $page->heading,		    
		    'description'   => $description,
		    'area'					=> $page->area,
		    'breadcrumbs'   => $active_breadcrumb,
		    'content'       => htmlspecialchars_decode($page->body),
		    'links'					=> $links,
		));
	}

	public function showSubPage($area, $slug)
	{
	    $page = Page::where('slug', $slug)->first();
	    $parent = Page::where('slug', $area)->first();
	    $links = Page::where('area', $area)->where('slug', '!=', $slug)->get();
	    
	    $breadcrumb = '<li>'.link_to($page['area'], $parent->heading).'&nbsp</li><li class="active">'.$page->heading.'</li>';
	    $description = '<meta name="description" content="'.$page->description.'">';
	    
		$this->layout->content = View::make('pages.page', array(
		    'slug'          => $page->slug,
		    'heading'       => $page->heading,		    
		    'description'   => $description,
		    'area'					=> $page->area,
		    'breadcrumbs'   => $breadcrumb,
		    'content'       => htmlspecialchars_decode($page->body),
		    'links'					=> $links,
		));
	}

}
