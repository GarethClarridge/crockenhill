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

			//User
			$user = \Auth::user();

			$page = \Crockenhill\Page::where('slug', $slug)->first();

			//Description
		  $description 	= '<meta name="description" content="'.$page->description.'">';

			//Heading
			$heading = $page->heading;

			//Heading picture
			$headingpicture = '/images/headings/large/'.$slug.'.jpg';

			//Breadcrumbs
		  if ($area != $slug) {
		  	$areapage = \Crockenhill\Page::where('slug', $area)->first();
		  	$breadcrumbs 	= '<li><a href="/'.$area.'">'.$areapage->heading.'</a></li>
													<li class="active">'.$page->heading.'</li>';
		  } else {
		  	$breadcrumbs 	= '<li class="active">'.$page->heading.'</li>';
			}

			//Content
			$content = htmlspecialchars_decode($page->body);

			//Links
			if ($area !== 'members') {
        $links = \Crockenhill\Page::where('area', $area)
          ->where('slug', '!=', $slug)
          ->where('slug', '!=', $area)
					->where('slug', '!=', 'privacy-policy')
					->where('admin', '!=', 'yes')
					->orderBy(\DB::raw('RAND()'))
          ->take(5)
          ->get();
      } else {
				$links = NULL;
			}

      $view->with([
				'slug'						=> $slug,
				'area'						=> $area,
				'description'   	=> $description,
				'heading'       	=> $heading,
				'headingpicture' 	=> $headingpicture,
				'breadcrumbs'   	=> $breadcrumbs,
				'content'					=> $content,
				'links'						=> $links,
				'user' 						=> $user,
			]);
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
