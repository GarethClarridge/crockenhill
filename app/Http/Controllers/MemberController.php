<?php namespace Crockenhill\Http\Controllers;

class MemberController extends Controller {

	public function home()
	{
        // Attempt to find a page for members home, or use defaults
        $page = \Crockenhill\Page::where('slug', 'members-home')->orWhere('slug', 'members')->first();

        $heading = $page->heading ?? 'Members Area';
        $description_meta = $page ? '<meta name="description" content="'.e($page->description).'">' : '<meta name="description" content="Members area for Crockenhill Baptist Church.">';
        $content = $page ? htmlspecialchars_decode($page->body) : '<p>Welcome to the members area.</p>';

        return view('members.home', [
            'heading' => $heading,
            'description' => $description_meta, // For the layout's meta description
            'content' => $content, // For the layout's main content area
            // 'page' => $page // Optionally pass the whole page object if view needs more
        ]);
  }
}
