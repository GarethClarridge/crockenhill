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
			//User
			$user = \Auth::user();

			//Set name, slug and area from url
			if (\Request::segment(3)) {

      }
			elseif (\Request::segment(2)) {

      }
			else {
				$name = NULL;

			}

			//Songs
			if (\Request::segment(2) == 'songs' && \Request::segment(4)){
				$name = \Request::segment(3);
				$slug = \Request::segment(2);
				$area = \Request::segment(1);

				// Look up song in songs table of database
		    $song =\Crockenhill\Song::where('id', $name)->first();

				//Heading picture
				$headingpicture = '/images/headings/large/'.$slug.'.jpg';

		    // Find relevant links
		    $links = \Crockenhill\Page::where('area', $area)
		      ->where('slug', '!=', $area)
		      ->where('slug', '!=', 'homepage')
		      ->orderBy(\DB::raw('RAND()'))
		      ->take(5)
		      ->get();

		    // Set values
		    $breadcrumbs = '<li class="breadcrumb-item"><a href="/members">Members</a></li>
		                    <li class="breadcrumb-item"><a href="/members/songs">Songs</a></li>
		                    <li class="breadcrumb-item active">'.$song->title.'</li>';

				// Set heading
				if (is_null($song->alternative_title)) {
		      $heading = $song->title;
		    } else {
		      $heading = $song->title.' - ('.$song->alternative_title.')';
		    }

				//Description
				$description 	= '<meta name="description" content="'.$slug.': '.$name.'">';

			}

			//Sermons
			if (\Request::segment(1) == 'sermons' && \Request::segment(4)){
				$slug = \Request::segment(4);
				$month = \Request::segment(3);
				$year = \Request::segment(2);
				$area = \Request::segment(1);

				$sermon = \Crockenhill\Sermon::where('slug', $slug)
		                                    ->whereBetween('date', array($year.'-'.$month.'-01', $year.'-'.$month.'-31'))
		                                    ->first();

				$heading = $sermon->title;

				//Heading picture
				$headingpicture = '/images/headings/large/'.$slug.'.jpg';

				// Find relevant links
				$links = \Crockenhill\Page::where('area', $area)
					->where('slug', '!=', $area)
					->where('slug', '!=', 'homepage')
					->orderBy(\DB::raw('RAND()'))
					->take(5)
					->get();

				$breadcrumbs 	= '<li class="breadcrumb-item"><a href="/sermons">Sermons</a></li>
												 <li class="breadcrumb-item active">'.$heading.'</li>';

				//Description
				$description 	= '<meta name="description" content="'.$heading.'">';
			}

			//Auth
			if ((\Request::segment(1) == 'login')
							|| (\Request::segment(1) == 'register')
							|| (\Request::segment(1) == 'password')){

				$slug = \Request::segment(1);
				$area = 'Members';

				$heading = title_case($slug);

				//Heading picture
				$headingpicture = '/images/headings/large/'.$area.'.jpg';

				// Find relevant links
				$links = \Crockenhill\Page::where('area', $area)
					->where('slug', '!=', $area)
					->where('slug', '!=', 'homepage')
					->orderBy(\DB::raw('RAND()'))
					->take(5)
					->get();

				$breadcrumbs 	= '<li class="breadcrumb-item"><a href="/sermons">Members</a></li>';

				//Description
				$description 	= '<meta name="description" content="'.$heading.'">';
			}

			//Level 3
			elseif (\Request::segment(3)) {
				$name = \Request::segment(3);
				$slug = \Request::segment(2);
				$area = \Request::segment(1);

				//Description
				$description 	= '<meta name="description" content="'.$slug.': '.$name.'">';

				//Heading
				$heading = str_replace("-", " ", title_case($name));

				//Breadcrumbs
				$areapage = \Crockenhill\Page::where('slug', $area)->first();
				$slugpage = \Crockenhill\Page::where('slug', $slug)->first();
				$breadcrumbs 	= '<li class="breadcrumb-item"><a href="/'.$area.'">'.$areapage->heading.'</a></li>
												 <li class="breadcrumb-item"><a href="/'.$area.'/'.$slug.'">'.$slugpage->heading.'</a></li>
												 <li class="breadcrumb-item active">'.$heading.'</li>';

				//Heading picture
				$headingpicture = '/images/headings/large/'.$slug.'.jpg';

				//Links
				$links = \Crockenhill\Page::where('area', $area)
					->where('slug', '!=', $slug)
					->where('slug', '!=', $area)
					->where('slug', '!=', 'privacy-policy')
					->where('admin', '!=', 'yes')
					->orderBy(\DB::raw('RAND()'))
					->take(5)
					->get();
			}

			//Level 2
			elseif (\Request::segment(2)) {
				$slug = \Request::segment(2);
				$area = \Request::segment(1);

				//Load page
				if($page = \Crockenhill\Page::where('slug', $slug)->first()) {
					//Description
					$description 	= '<meta name="description" content="'.$page->description.'">';

					//Heading
					$heading = $page->heading;

					//Breadcrumbs
					$areapage = \Crockenhill\Page::where('slug', $area)->first();
					$breadcrumbs 	= '<li class="breadcrumb-item"><a href="/'.$area.'">'.$areapage->heading.'</a></li>
													 <li class="breadcrumb-item active">'.$heading.'</li>';

					//Content
		 			$content = htmlspecialchars_decode($page->body);
				} else {
					$description = NULL;
					$heading = NULL;
					$breadcrumbs = NULL;
				}

				//Heading picture
				$headingpicture = '/images/headings/large/'.$slug.'.jpg';

				//Links
				$links = \Crockenhill\Page::where('area', $area)
					->where('slug', '!=', $slug)
					->where('slug', '!=', $area)
					->where('slug', '!=', 'privacy-policy')
					->where('admin', '!=', 'yes')
					->orderBy(\DB::raw('RAND()'))
					->take(5)
					->get();

			//Level 1
			} else {
				$area = \Request::segment(1);

				//Load page
				$page = \Crockenhill\Page::where('slug', $area)->first();

				//Description
				$description 	= '<meta name="description" content="'.$page->description.'">';

				//Heading
				$heading = $page->heading;

				//Breadcrumbs
				$breadcrumbs 	= '<li class="breadcrumb-item active">'.$heading.'</li>';

				//Content
	 			$content = htmlspecialchars_decode($page->body);

				//Heading picture
				$headingpicture = '/images/headings/large/'.$area.'.jpg';

				//Links
				$links = \Crockenhill\Page::where('area', $area)
					->where('slug', '!=', $area)
					->where('slug', '!=', 'privacy-policy')
					->where('admin', '!=', 'yes')
					->orderBy(\DB::raw('RAND()'))
					->take(5)
					->get();
			}

      $view->with([
				'name'						=> (isset($name) ? $name : ''),
				'slug'						=> (isset($slug) ? $slug : ''),
				'area'						=> $area,
				'description'   	=> $description,
				'heading'       	=> $heading,
				'headingpicture' 	=> $headingpicture,
				'breadcrumbs'   	=> $breadcrumbs,
				'content'					=> (isset($content) ? $content : ''),
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
