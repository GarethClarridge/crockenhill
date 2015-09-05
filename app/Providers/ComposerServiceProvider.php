<?php namespace Crockenhill\Providers;

use Illuminate\Support\ServiceProvider;

class ComposerServiceProvider extends ServiceProvider {

	/**
	 * Bootstrap the application services.
	 *
	 * @return void
	 */
	public function boot()
	{
		\View::composer('includes.header', function($view)
    {
      $pages = array(

        'AboutUs' => array('route'=> 'about-us', 'name' => 'About Us'),
        'WhatsOn' => array('route'=> 'whats-on', 'name' => 'What\'s On'),
        'FindUs' => array('route'=> 'find-us', 'name' => 'Find Us'),
        'ContactUs' => array('route' => 'contact-us', 'name' => 'Contact Us'),
        'Sermons' =>array('route'=> 'sermons', 'name' => 'Sermons'),
        'Members' =>array('route'=> 'members', 'name' => 'Members'),
      
      );
      
      $view->with('pages', $pages);
        
    });
    
		\View::composer('includes.footer', function($view)
		    {
	        //get the latest sermons
	        $morning = \Crockenhill\Sermon::where('service', 'morning')
	           ->orderBy('date', 'desc')->first();
	        $evening = \Crockenhill\Sermon::where('service', 'evening')
	           ->orderBy('date', 'desc')->first();

	        // and create the view composer
	        $view->with('morning', $morning);
	        $view->with('evening', $evening);
		    });

		\View::composer('layouts.members', function($view)
    {
      $area = 'members';
      $links = \Crockenhill\Page::where('area', $area)->orderBy(\DB::raw('RAND()'))->take(5)->get();

      $view->with('links', $links);
    });

    \View::composer('page', function($view)
    {
      if (\Request::segment(2)) {
        $slug = \Request::segment(2);
        $area = \Request::segment(1);
      } else {
        $slug = \Request::segment(1);
        $area = \Request::segment(1);
      }
      
      $headingpicture = '/images/headings/large/'.$slug.'.jpg';
      if ($area != 'whats-on') {
        $links = \Crockenhill\Page::where('area', $area)
          ->where('slug', '!=', $slug)
          ->where('slug', '!=', $area)
          ->take(5)
          ->get();
      } else {
        $links = \Crockenhill\Meeting::where('slug', '!=', $slug)
          ->get();
      }
      
      $view->with('headingpicture', $headingpicture);
      $view->with('links', $links);
    });

    //Get user in sermons index page
    \View::composer('sermons.index', function($view)
    {
      if (\Auth::user()) {
        $user = \Auth::user();
      } else {
        $user = null;
      }
      
      $view->with('user', $user);
    });
  }

	/**
	 * Register the application services.
	 *
	 * @return void
	 */
	public function register()
	{
		//
	}

}
