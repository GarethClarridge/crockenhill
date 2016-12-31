<?php namespace Crockenhill\Http\Controllers;

class MemberController extends Controller {

	public function home()
	{
		$slug = 'members';
		$area = 'members';
		$heading = 'Members';
		$description = 'Part of the website for church members';
		$breadcrumbs = '<li class="active">'.$heading.'</li>';
		$content = '';
		return view('members.home', array(
			'slug'          => $slug,
			'heading'       => $heading,
			'description'   => $description,
			'area'					=> $area,
			'breadcrumbs'   => $breadcrumbs,
			'content'       => $content,
		));
  }
}
