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

		\View::composer('includes.photoselector', function($view)
		{
			$photo_directory = '/images/photos';
			$public_photo_directory = public_path().$photo_directory;
			$photos = array_diff(scandir($public_photo_directory), array('..', '.'));

			$view->with('photo_directory', $photo_directory);
			$view->with('photos', $photos);
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

			if ($area !== 'whats-on' && $area !== 'members') {
        $links = \Crockenhill\Page::where('area', $area)
          ->where('slug', '!=', $slug)
          ->where('slug', '!=', $area)
					->where('slug', '!=', 'privacy-policy')
					->where('admin', '!=', 'yes')
					->orderBy(\DB::raw('RAND()'))
          ->take(5)
          ->get();
      } else if ($area == 'whats-on') {
        $links = \Crockenhill\Meeting::where('slug', '!=', $slug)
          ->get();
      } else {
				$links = NULL;
			}

			$user = \Auth::user();

      $view->with('headingpicture', $headingpicture);
      $view->with('links', $links);
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
