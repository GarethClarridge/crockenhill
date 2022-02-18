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
      // \View::composer('includes.header', function($view)
      // {
      // $pages = array(
      //   'christ' => array('route'=> 'christ', 'name' => 'Christ'),
      //   'church' => array('route'=> 'church', 'name' => 'Church'),
      //   'community' => array('route'=> 'community', 'name' => 'Community'),
      // );
      //   $view->with('pages', $pages);
      // });

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
          $links = \Crockenhill\Page::where('area', $slug)
            ->where('slug', '!=', $slug)
            ->where('slug', '!=', 'homepage')
            ->orderBy(\DB::raw('RAND()'))
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
                                          ->whereBetween('date', array($year.'-'.$month.'-01', $year.'-'.$month.'-31'))
                                          ->first();

          $heading = $sermon->title;

          //Heading picture
          $headingpicture = '/images/headings/large/'.$sermon_slug.'.jpg';

          // Find relevant links
          $links = \Crockenhill\Page::where('area', $slug)
            ->where('slug', '!=', $slug)
            ->where('slug', '!=', 'homepage')
            ->orderBy(\DB::raw('RAND()'))
            ->take(5)
            ->get();

          //Description
          $description 	= '<meta name="description" content="'.$heading.'">';
        }

        //Auth
        elseif ((\Request::segment(1) == 'login')
                || (\Request::segment(1) == 'register')
                || (\Request::segment(1) == 'password')){

          $area = 'Members';

          if (isset($page->heading)) {
            $heading = $page->heading;
          }
          else {
            $heading = title_case($slug);
          }

          //Heading picture
          $headingpicture = '/images/headings/large/'.$area.'.jpg';

          // Find relevant links
          $links = \Crockenhill\Page::where('area', $area)
            ->where('slug', '!=', $area)
            ->where('slug', '!=', 'homepage')
            ->orderBy(\DB::raw('RAND()'))
            ->take(5)
            ->get();

          //Description
          $description 	= '<meta name="description" content="'.$heading.'">';
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
              ->where('slug', '!=', 'privacy-policy')
              ->where('admin', '!=', 'yes')
              ->orderBy('slug', 'asc')
              ->get();
          }
          else if (\Request::segment(2) == 'members') {
            $links = \Crockenhill\Page::where('area', 'sermons')
              ->where('slug', '!=', $slug)
              ->where('slug', '!=', $area)
              ->where('slug', '!=', 'privacy-policy')
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

          //Load page
          if($page = \Crockenhill\Page::where('slug', $slug)->first()) {
            //Description
            $description 	= '<meta name="description" content="'.$page->description.'">';

            //Heading
            $heading = $page->heading;

            //Content
            $content = htmlspecialchars_decode($page->body);
          } else {
            $description = NULL;
            $heading = NULL;
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
            $meeting = \Crockenhill\Meeting::where('slug', $slug)->first();

            $related_meetings = \Crockenhill\Meeting::where('type', $meeting->type)
            ->pluck('slug');

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
          $page = \Crockenhill\Page::where('slug', $area)->first();

          //Description
          $description 	= '<meta name="description" content="'.$page->description.'">';

          //Heading
          $heading = $page->heading;

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
          'slug'						=> (isset($slug) ? $slug : $area),
          'area'						=> $area,
          'description'   	=> $description,
          'heading'       	=> $heading,
          'headingpicture' 	=> $headingpicture,
          'content'					=> (isset($content) ? $content : ''),
          'links'						=> $links,
          'user' 						=> $user,
        ]);
      });








      \View::composer('layouts/article-without-asides', function($view)
      {
        //User
        $user = \Auth::user();

        //Set area from url
        $area = \Request::segment(1);

        //Load page
        $page = \Crockenhill\Page::where('slug', $area)->first();

        //Description
        $description 	= '<meta name="description" content="'.$page->description.'">';

        //Heading
        $heading = $page->heading;

        //Content
        $content = htmlspecialchars_decode($page->body);

        //Heading picture
        $headingpicture = '/images/headings/large/'.$area.'.jpg';

        $view->with([
          'name'						=> (isset($name) ? $name : ''),
          'slug'						=> (isset($slug) ? $slug : $area),
          'area'						=> $area,
          'description'   	=> $description,
          'heading'       	=> $heading,
          'headingpicture' 	=> $headingpicture,
          'content'					=> (isset($content) ? $content : ''),
          'user' 						=> $user,
        ]);
      });
    }
}
