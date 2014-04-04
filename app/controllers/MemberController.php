<?php

class MemberController extends BaseController {

  protected $layout = 'layouts.main';

	public function documents()
	{
    $slug = 'documents';
		$page = Page::where('slug', $slug)->first();
    $area = 'members';
    $breadcrumbs = '<li>'.link_to('members', 'Members').'&nbsp</li><li class="active">'.$page->heading.'</li>';
    $links = Page::where('area', $area)
        ->where('slug', '!=', $slug)
        ->where('slug', '!=', $area)
        ->where('slug', '!=', 'homepage')
        ->orderBy(DB::raw('RAND()'))
        ->take(5)
        ->get();      
    $description = '<meta name="description" content="'.$page->description.'">';
      
    $this->layout->content = View::make('pages.members.documents', array(
        'slug'          => $page->slug,
        'heading'       => $page->heading,        
        'description'   => $description,
        'area'          => $page->area,
        'breadcrumbs'   => $breadcrumbs,
        'content'       => htmlspecialchars_decode($page->body),
        'links'         => $links,
    ));

  }
}
