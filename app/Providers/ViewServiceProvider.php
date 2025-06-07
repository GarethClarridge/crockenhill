<?php

namespace Crockenhill\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @param  Request  $request
     * @return Response
     */
    public function boot(Request $request)
    {
      \View::composer('includes.header', function($view)
      {
        $pages = \Crockenhill\Page::where('navigation', 1)
                                ->orderBy('slug')
                                ->get();
        $view->with('pages', $pages);
      });

      \View::composer('includes.footer', function($view)
          {
            //get the latest sermons
            $morning = \Crockenhill\Sermon::where('service', 'morning')
               ->orderBy('date', 'desc')->first();
            $evening = \Crockenhill\Sermon::where('service', 'evening')
               ->orderBy('date', 'desc')->first();
            $evening = \Crockenhill\Sermon::where('service', 'evening')
               ->orderBy('date', 'desc')->first();

            // Pass safe data to the view to prevent errors with null objects
            $view->with([
                'morning_sermon_exists' => (bool) $morning,
                // 'morning_sermon_title' => optional($morning)->title, // Example if title was needed
                'evening_sermon_exists' => (bool) $evening,
                // 'evening_sermon_title' => optional($evening)->title, // Example if title was needed
            ]);
          });

      \View::composer('includes.photo-selector', function($view)
      {
        $photo_directory = '/images/photos';
        $public_photo_directory = public_path().$photo_directory;
        $photos = array_diff(scandir($public_photo_directory), array('..', '.'));

        $view->with('photo_directory', $photo_directory);
        $view->with('photos', $photos);
      });

      \View::composer('layouts/page', function($view)
      {
        //User
        $user = \Auth::user();

        //Set name, slug and area from url
        if (\Request::segment(3)) {
          $name = \Request::segment(3);
          $slug = \Request::segment(2);
          $area = \Request::segment(1);
        }
        elseif (\Request::segment(2)) {
          $name = NULL;
          $slug = \Request::segment(2);
          $area = \Request::segment(1);
        }
        else {
          $name = NULL;
          $slug = \Request::segment(1);
          $area = \Request::segment(1);
        }

        //Songs
        if (\Request::segment(3) == 'songs' && \Request::segment(5)){

          // Look up song in songs table of database
          $songnumber = \Request::segment(4);
          $song =\Crockenhill\Song::where('id', $songnumber)->first();

          //Heading picture
          $headingpicture = '/images/headings/large/'.$name.'.jpg';

          // Find relevant links
          // All DB::raw('RANDOM()') calls were already corrected in previous steps.
          // This search block is just for context. No change here if already RANDOM().
          $links = \Crockenhill\Page::where('area', $slug)
            ->where('slug', '!=', $slug)
            ->where('slug', '!=', 'homepage')
            ->orderBy(\DB::raw('RANDOM()'))
            ->take(5)
            ->get();

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
        elseif (\Request::segment(2) == 'sermons' && \Request::segment(5)){
          $sermon_slug = \Request::segment(5);
          $month = \Request::segment(4);
          $year = \Request::segment(3);

          $sermon = \Crockenhill\Sermon::where('slug', $sermon_slug)
                                          ->whereMonth('date', '=', $month)
                                          ->whereYear('date', '=', $year)
                                          ->first();

          $heading = $sermon->title;

          //Heading picture
          $headingpicture = '/images/headings/large/'.$sermon_slug.'.jpg';

          // Find relevant links
          // All DB::raw('RANDOM()') calls were already corrected.
          // This search block is just for context. No change here if already RANDOM().
          $links = \Crockenhill\Page::where('area', $slug)
            ->where('slug', '!=', $slug)
            ->where('slug', '!=', 'homepage')
            ->orderBy(\DB::raw('RANDOM()'))
            ->take(5)
            ->get();

          //Description
          $description 	= '<meta name="description" content="'.$heading.'">';
        }

        //Auth
        elseif ((\Request::segment(1) == 'login')
                || (\Request::segment(1) == 'register')
                || (\Request::segment(1) == 'password')){

          $area_context = 'Members'; // Context for links
          $page_slug_for_auth = \Request::segment(1); // e.g. 'login', 'register'
          $page_obj = \Crockenhill\Page::where('slug', $page_slug_for_auth)->first(); // Use $page_obj

          if ($page_obj) {
            $heading = $page_obj->heading ?? title_case($slug); // $slug is segment(1) here
            $description_meta_content = $page_obj->description ?? $heading;
            $content = $page_obj->content ? htmlspecialchars_decode($page_obj->content) : '<p>Please '. e($page_slug_for_auth) .'.</p>'; // Use content, e()
          } else {
            $heading = title_case($slug); // e.g. "Login", "Register"
            $description_meta_content = $heading;
            $content = '<p>Please '. e($page_slug_for_auth) .'.</p>'; // Default content, e()
          }
          $description 	= '<meta name="description" content="'.e($description_meta_content).'">';


          //Heading picture
          $headingpicture = '/images/headings/large/'.$area.'.jpg'; // Or a generic auth image

          // Find relevant links
          $links = \Crockenhill\Page::where('area', $area) // Using 'Members' as area for links
            ->where('slug', '!=', $page_slug_for_auth) // Exclude current page slug
            ->where('slug', '!=', 'homepage')
            ->orderBy(\DB::raw('RANDOM()'))
            ->take(5)
            ->get();

        }

        //Level 3
        elseif (\Request::segment(3)) {

          //Description
          $description 	= '<meta name="description" content="'.$slug.': '.$name.'">';

          //Heading
          if ($view->heading) {
            $heading = $view->heading;
          }
          else {
            $heading = str_replace("-", " ", title_case(request()->segment(count(request()->segments()))));
          }

          //Heading picture
          $headingpicture = '/images/headings/large/'.$slug.'.jpg';

          //Links
          if (\Request::segment(2) == 'sermons') {
            $links = \Crockenhill\Page::where('area', 'sermons')
              ->where('slug', '!=', $slug)
              ->where('slug', '!=', $area)
              ->orderBy('slug', 'asc')
              ->get();
          }
          else if (\Request::segment(2) == 'members') {
            $links = \Crockenhill\Page::where('area', 'sermons')
              ->where('slug', '!=', $slug)
              ->where('slug', '!=', $area)
              ->where('admin', '!=', 'yes')
              ->orderBy('slug', 'asc')
              ->get();
          }
          else {
          $links = \Crockenhill\Page::where('area', $area)
            ->where('slug', '!=', $slug)
            ->where('slug', '!=', $area)
            ->where('slug', '!=', 'privacy-policy')
            ->where('admin', '!=', 'yes')
            ->orderBy('slug', 'asc')
            ->get();
          }
        }

        //Level 2
        elseif (\Request::segment(2)) {

          //Load page data only if not already provided by controller (e.g. for Meeting page)
          if (!$view->offsetExists('heading')) { // Check if controller already set a heading
            // Use $page_obj to avoid conflict with $page from other scopes if this composer is nested
            if($page_obj = \Crockenhill\Page::where('slug', $slug)->first()) {
              $description_meta_content = $page_obj->description ?? $page_obj->heading ?? title_case($slug);
              $description 	= '<meta name="description" content="'.e($description_meta_content).'">';
              $heading = $page_obj->heading ?? title_case($slug);
              $content = $page_obj->content ? htmlspecialchars_decode($page_obj->content) : ''; // Use content, provide default
            } else {
              $description_meta_content = title_case($slug);
              $description = '<meta name="description" content="'.e($description_meta_content).'">'; // Default description
              $heading = title_case($slug);
              $content = ''; // Default content
            }
          } else { // Controller did set a heading (likely means controller passed all necessary data)
            $heading = $view->offsetGet('heading');
            $description = $view->offsetExists('description') ? $view->offsetGet('description') : '<meta name="description" content="'.e($heading ?? $slug).'">'; // Fallback description
            $content = $view->offsetExists('content') ? $view->offsetGet('content') : ''; // Fallback content
          }

          //Heading picture
          $headingpicture = '/images/headings/large/'.$slug.'.jpg';

          //Links
          if (\Request::segment(2) == 'sermons') {
            $links = \Crockenhill\Page::where('area', 'sermons')
              ->where('slug', '!=', $slug)
              ->where('slug', '!=', $area)
              ->where('admin', '!=', 'yes')
              ->orderBy('slug', 'asc')
              ->get();
          }
          else if (\Request::segment(2) == 'members') {
            $links = \Crockenhill\Page::where('area', 'sermons')
              ->where('slug', '!=', $slug)
              ->where('slug', '!=', $area)
              ->where('admin', '!=', 'yes')
              ->orderBy('slug', 'asc')
              ->get();
          }
          else if (\Request::segment(1) == 'community'){
            $related_meetings = []; // Initialize at the start of this block
            // If we are on a specific meeting page (e.g., community/{id}),
            // the controller already provides $meeting.
            // This logic might be for a generic /community/some-other-slug page.
            // We need to be careful not to overwrite or conflict with controller-provided data.
            // For now, ensure $meeting is not null before using it.
            $current_meeting_from_composer = null;
            if ($slug && !$view->offsetExists('meeting')) { // Only try to fetch if controller hasn't set it
                 $current_meeting_from_composer = \Crockenhill\Meeting::where('slug', $slug)->first();
            }

            // $related_meetings = []; // Moved initialization up
            if ($current_meeting_from_composer) {
                $related_meetings = \Crockenhill\Meeting::where('type', $current_meeting_from_composer->type)
                                                              ->where('slug', '!=', $current_meeting_from_composer->slug) // Exclude self
                                                              ->pluck('slug'); // Returns a Collection
            } elseif ($view->offsetExists('meeting')) {
                // Use meeting object passed by the controller (e.g. from route-model binding)
                $controller_meeting = $view->offsetGet('meeting');
                if ($controller_meeting instanceof \Crockenhill\Meeting) {
                    $related_meetings = \Crockenhill\Meeting::where('type', $controller_meeting->type)
                                                                  ->where('id', '!=', $controller_meeting->id) // Exclude self by ID
                                                                  ->pluck('slug'); // Returns a Collection
                }
            }

            // whereIn can handle an empty array or a Collection. If it's a collection, it gets items.
            $links = \Crockenhill\Page::where('area', $area)
              ->whereIn('slug', $related_meetings)
              ->where('slug', '!=', $slug)
              ->where('slug', '!=', $area)
              ->where('admin', '!=', 'yes')
              ->orderBy('slug', 'asc')
              ->get();
          }
          else {
            $links = \Crockenhill\Page::where('area', $area)
              ->where('slug', '!=', $slug)
              ->where('slug', '!=', $area)
              ->where('slug', '!=', 'privacy-policy')
              ->where('slug', '!=', 'attending-in-person')
              ->where('slug', '!=', 'resources')
              ->where('admin', '!=', 'yes')
              ->orderBy('slug', 'asc')
              ->get();
          }


        //Level 1
        } else {

          //Load page
          $page_obj_level1 = \Crockenhill\Page::where('slug', $area)->first(); // Renamed variable

          if ($page_obj_level1) {
            $description_meta_content = $page_obj_level1->description ?? $page_obj_level1->heading ?? title_case($area);
            $description 	= '<meta name="description" content="'.e($description_meta_content).'">';
            $heading = $page_obj_level1->heading ?? title_case($area);
            $content = $page_obj_level1->content ? htmlspecialchars_decode($page_obj_level1->content) : ''; // Use content
          } else {
            // Fallbacks if page not found
            $description_meta_content = 'Welcome to '.title_case($area);
            $description = '<meta name="description" content="'.e($description_meta_content).'">';
            $heading = title_case($area);
            $content = ''; // Default content
          }

          //Heading picture
          $headingpicture = '/images/headings/large/'.$area.'.jpg';

          //Links
          $links = \Crockenhill\Page::where('area', $area)
            ->where('slug', '!=', $area)
            ->where('slug', '!=', 'privacy-policy')
            ->where('admin', '!=', 'yes')
            ->orderBy(\DB::raw('RANDOM()')) // Changed RAND() to RANDOM() for SQLite
            ->take(5)
            ->get();
        }

        $view->with([
          'name'						=> (isset($name) ? $name : ''),
          'slug'						=> (isset($slug) ? $slug : $area),
          'area'						=> $area, // This is the URL segment
          'description'   	=> $description ?? $view->offsetGet('description'), // Prioritize controller data
          'heading'       	=> $heading ?? $view->offsetGet('heading'),       // Prioritize controller data
          'headingpicture' 	=> $headingpicture,
          'content'					=> $content ?? $view->offsetGet('content'),       // Prioritize controller data
          'links'						=> $links,
          'user' 						=> $user,
          'pages'           => (isset($pages) ? $pages : '') // This is from includes.header composer
        ]);
      });

      \View::composer(['full-width-pages.home', 'full-width-pages.community'], function($view)
      {
        $pages = \Crockenhill\Page::all();

        $view->with([
          'pages'           => (isset($pages) ? $pages : '')
        ]);

      });

      \View::composer('full-width-pages.church', function($view)
      {
        $links = \Crockenhill\Page::where('area', 'church')
            ->where('slug', '!=', 'privacy-policy')
            ->where('slug', '!=', 'safeguarding-policy')
            ->where('admin', '!=', 'yes')
            ->get();

        $pages = \Crockenhill\Page::all();

        $view->with([
          'pages' => (isset($pages) ? $pages : ''),
          'links' => (isset($links) ? $links : '')
        ]);

      });


    }
}
