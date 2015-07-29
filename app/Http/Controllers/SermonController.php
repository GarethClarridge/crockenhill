<?php namespace Crockenhill\Http\Controllers;

class SermonController extends BaseController {

  protected $layout = 'layouts.main';

	public function index()
	{

		$slug = 'sermons';
		$area = 'sermons';
		
  	    $page = \Crockenhill\Page::where('slug', $slug)->first();

		$links = \Crockenhill\Page::where('area', $area)
    	->where('slug', '!=', $slug)
    	->where('slug', '!=', $area)
    	->where('slug', '!=', 'homepage')
    	->orderBy(\DB::raw('RAND()'))
    	->take(5)
    	->get();

      	$heading = 'Sermons';
      	$breadcrumbs = '<li class="active">'.$page->heading.'</li>';
      	$content = $page->body;

      	$latest_morning_sermons = \Crockenhill\Sermon::where('service', 'morning')
													->orderBy('date', 'desc')
													->take(3)
													->get();
      	$latest_evening_sermons = \Crockenhill\Sermon::where('service', 'evening')
													->orderBy('date', 'desc')
													->take(3)
													->get();
	    
		return \View::make('pages.sermons.index', array(
		    'slug'          					=> $slug,
		    'heading'       					=> $heading,		    
		    'description'   					=> '<meta name="description" content="Recent sermons preached at Crockenhill Baptist Church.">',
            'area'								=> $area,
		    'breadcrumbs'   					=> $breadcrumbs,
		    'content'       					=> $content,
            'links'								=> $links,
		    'latest_morning_sermons' 	        => $latest_morning_sermons,
		    'latest_evening_sermons' 	        => $latest_evening_sermons
		));
	}

	public function show($slug)
	{
		$sermon = \Crockenhill\Sermon::where('slug', $slug)->first();
		$area = 'sermons';

		$links = \Crockenhill\Page::where('area', $area)
    	->where('slug', '!=', $slug)
    	->where('slug', '!=', $area)
    	->where('slug', '!=', 'homepage')
    	->orderBy(\DB::raw('RAND()'))
    	->take(5)
    	->get();

      	$heading = $sermon->title;
      	$breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
      	                <li><a href="series/'.$sermon->series.'">'.$sermon->series.'</a></li>
      	                <li class="active">'.$sermon->title.'</li>
      	                ';

        // Get the passage
        $reference = $sermon->reference;
        
        $section = array();
        $key = "IP";
        $passage = urlencode($reference);
        $options = "include-passage-references=false&audio-format=flash";
          $url = "http://www.esvapi.org/v2/rest/passageQuery?key=$key&passage=$passage&$options";
          $data = fopen($url, "r") ;

          if ($data)
          {
             while (!feof($data))
             {
                $buffer = fgets($data, 4096);
                $section[] = $buffer;
             }
             fclose($data);
          }
          else
          {
             die("fopen failed for url to webservice");
          }
        
      	return \View::make('pages.sermons.sermon', array(
		    'slug'              => $slug,
		    'heading'           => $heading,		    
		    'description'       => '<meta name="description" content="'.$sermon->heading.': a sermon preached at Crockenhill Baptist Church.">',
		    'area'				=> $area,
		    'breadcrumbs'       => $breadcrumbs,
		    'content'			=> '',
		    'links'				=> $links,
		    'sermon' 			=> $sermon,
		    'passage'         => $section
		));
	}

	public function preacherIndex()
	{

		$slug = 'preacher';

		$page = \Crockenhill\Page::where('slug', $slug)->first();

		$area = $page->area;

		$links = \Crockenhill\Page::where('area', $area)
        	->where('slug', '!=', $slug)
        	->where('slug', '!=', $area)
        	->where('slug', '!=', 'homepage')
        	->orderBy(\DB::raw('RAND()'))
        	->take(5)
        	->get();

		$area_heading = Str::title($page->area);

      	$breadcrumbs = '<li><a href="/'.$page->area.'">'.$area_heading.'</a></li><li class="active">'.$page->heading.'</li>';

      	$preachers = \Sermon::select('preacher')->distinct()->orderBy('preacher', 'asc')->get();
      	$preacher_array = array();

      	foreach ($preachers as $preacher) {
      		$count = \Crockenhill\Sermon::where('preacher', $preacher->preacher)->count();
      		$preacher_array[$preacher->preacher] = array($count, $preacher->preacher);
      	}

      	arsort($preacher_array);

      	return \View::make('pages.sermons.preachers', array(
	        'slug'        => $slug,
	        'heading'     => $page->heading,		    
	        'description' => '<meta name="description" content="'.$page->description.'">',
	        'area'				=> $area,
	        'breadcrumbs' => $breadcrumbs,
	        'content'			=> $page->body,
	        'links'				=> $links,
	        'preachers'		=> $preacher_array
	    ));
	}

	public function preacherShow($preacher)
	{
		$preacher_name = str_replace('-', ' ', Str::title($preacher));
		$area = 'sermons';
		$links = \Crockenhill\Page::where('area', $area)		
        	->where('slug', '!=', $area)
        	->orderBy(\DB::raw('RAND()'))
        	->take(5)
        	->get();

		$area_heading = Str::title($area);

      	$breadcrumbs = '<li><a href="/'.$area.'">'.$area_heading.'</a></li><li class="active">'.$preacher_name.'</li>';

      	$sermons = \Crockenhill\Sermon::where('preacher', $preacher_name)->orderBy('date')->get();

      	return \View::make('pages.sermons.preacher', array(
	        'slug'        => '',
	        'heading'     => $preacher_name,		    
	        'description' => '<meta name="description" content="Sermons by '.$preacher_name.'">',
	        'area'				=> $area,
	        'breadcrumbs' => $breadcrumbs,
	        'content'			=> '',
	        'links'				=> $links,
	        'sermons'			=> $sermons
	    ));
	}

	public function seriesIndex()
	{
		$slug = 'series';
		$page = \Crockenhill\Page::where('slug', $slug)->first();
		$area = $page->area;
		$links = \Crockenhill\Page::where('area', $area)
        	->where('slug', '!=', $slug)
        	->where('slug', '!=', $area)
        	->where('slug', '!=', 'homepage')
        	->orderBy(\DB::raw('RAND()'))
        	->take(5)
        	->get();
		$area_heading = Str::title($page->area);
      	$breadcrumbs = '<li><a href="/'.$page->area.'">'.$area_heading.'</a></li><li class="active">'.$page->heading.'</li>';

      	$series = \Sermon::select('series')->distinct()->orderBy('date')->get();

      	return \View::make('pages.sermons.serieses', array(
	        'slug'        => $slug,
	        'heading'     => $page->heading,		    
	        'description' => '<meta name="description" content="'.$page->description.'">',
	        'area'				=> $area,
	        'breadcrumbs' => $breadcrumbs,
	        'content'			=> $page->body,
	        'links'				=> $links,
	        'series'		=> $series
	    ));
	}

	public function seriesShow($series)
	{
		$series_name = str_replace('-', ' ', Str::title($series));
		$area = 'sermons';
		$links = \Crockenhill\Page::where('area', $area)		
        	->where('slug', '!=', $area)
        	->orderBy(\DB::raw('RAND()'))
        	->take(5)
        	->get();
		$area_heading = Str::title($area);
      	$breadcrumbs = '<li><a href="/'.$area.'">'.$area_heading.'</a></li><li class="active">'.$series_name.'</li>';
      	$sermons = \Crockenhill\Sermon::where('series', $series_name)->orderBy('date')->get();

      	return \View::make('pages.sermons.series', array(
	        'slug'        => '',
	        'heading'     => $series_name,		    
	        'description' => '<meta name="description" content="Sermons by '.$series_name.'">',
	        'area'				=> $area,
	        'breadcrumbs' => $breadcrumbs,
	        'content'			=> '',
	        'links'				=> $links,
	        'sermons'			=> $sermons
	    ));
	}

}
