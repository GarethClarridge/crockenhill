<?php namespace Crockenhill\Http\Controllers;

use Crockenhill\Http\Requests;
use Crockenhill\Http\Controllers\Controller;

use Illuminate\Http\Request;

class SermonController extends Controller {

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
	{
		$slug = 'sermons';
  	$page = \Crockenhill\Page::where('slug', $slug)->first();

  	$latest_morning_sermons = \Crockenhill\Sermon::where('service', 'morning')
											->orderBy('date', 'desc')
											->take(3)
											->get();
  	$latest_evening_sermons = \Crockenhill\Sermon::where('service', 'evening')
												->orderBy('date', 'desc')
												->take(3)
												->get();
	    
		return view('sermons.index', array(
	    'slug'                    => $slug,
	    'heading'       			    => $page->heading,
	    'description'   			    => '<meta name="description" content="Recent sermons preached at Crockenhill Baptist Church.">',
	    'breadcrumbs'   					=> '<li class="active">'.$page->heading.'</li>',
	    'content'       					=> $page->body,
	    'latest_morning_sermons' 	=> $latest_morning_sermons,
	    'latest_evening_sermons' 	=> $latest_evening_sermons
		));
	}

	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
		$series = array_unique(\Crockenhill\Sermon::lists('series'));
    return view('sermons.create', array(
      'series'        => $series,
      'heading'       => 'Upload a new sermon',
      'description'   => '<meta name="description" content="Recent sermons preached at Crockenhill Baptist Church.">',
      'breadcrumbs'   => '<li><a href="/sermons">Sermons</a></li><li class="active">Create</li>',
      'content'       => '',

    ));
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store()
	{
		$file = \Input::file('file');
    $file->move('media/sermons', $file->getClientOriginalName());
    $filename = substr($file->getClientOriginalName(), 0, -4);

    if (substr($filename, -1) === 'b') {
        $service = 'evening';
    } else {
        $service = 'morning';
    }

    if (\Input::get('series')){
        $series = \Input::get('series');
    } else {
        $series = \Input::get('new_series');
    }

    $sermon = new \Crockenhill\Sermon;
    $sermon->title      = \Input::get('title');
    $sermon->filename   = $filename;
    $sermon->date       = \Input::get('date');
    $sermon->service    = $service;
    $sermon->slug       = \Illuminate\Support\Str::slug(\Input::get('title'));
    $sermon->series     = $series;
    $sermon->reference  = \Input::get('reference');
    $sermon->preacher   = \Input::get('preacher');
    $sermon->save();

    return redirect('sermons');
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($slug)
	{
		$sermon = \Crockenhill\Sermon::where('slug', $slug)->first();
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
    if ($data) {
      while (!feof($data)){
        $buffer = fgets($data, 4096);
        $section[] = $buffer;
      }
      fclose($data);
    }
    else {
      die("fopen failed for url to webservice");
    }
    
  	return view('sermons.sermon', array(
	    'slug'        => $slug,
	    'heading'     => $heading,		    
	    'description' => '<meta name="description" content="'.$sermon->heading.': a sermon preached at Crockenhill Baptist Church.">',
	    'breadcrumbs' => $breadcrumbs,
	    'content'			=> '',
	    'sermon' 			=> $sermon,
	    'passage'     => $section
		));
	}

	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit($slug)
	{
    $sermon = \Crockenhill\Sermon::where('slug', $slug)->first();
    $series = array_unique(\Crockenhill\Sermon::lists('series'));

    return view('sermons.edit', array(
      'sermon'        => $sermon,
      'series'        => $series,
      'heading'       => 'Edit this sermon',
      'description'   => '<meta name="description" content="Edit this sermon.">',
      'breadcrumbs'   => '<li><a href="/sermons">Sermons</a></li>
                          <li><a href="series/'.$sermon->series.'">'.$sermon->series.'</a></li>
                          <li><a href="series/'.$sermon->title.'">'.$sermon->title.'</a></li>
                          <li class="active">Edit</li>',
      'content'       => '',
    ));
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update($slug)
	{
		if (\Input::get('series')){
            $series = \Input::get('series');
        } else {
            $series = \Input::get('new_series');
        }

        $sermon = \Crockenhill\Sermon::where('slug', $slug)->first();
        $sermon->title      = \Input::get('title');
        $sermon->date       = \Input::get('date');
        $sermon->slug       = \Illuminate\Support\Str::slug(\Input::get('title'));
        $sermon->series     = $series;
        $sermon->reference  = \Input::get('reference');
        $sermon->preacher   = \Input::get('preacher');
        $sermon->save();

        return redirect('/sermons/'.$slug);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($slug)
	{
		$sermon = \Crockenhill\Sermon::where('slug', $slug)->first();
    $sermon->delete();

    return redirect('sermons');
	}

	public function getPreachers()
	{
		$slug = 'preachers';
		$page = \Crockenhill\Page::where('slug', $slug)->first();
		$area_heading = \Illuminate\Support\Str::title($page->area);
		$breadcrumbs = '<li><a href="/'.$page->area.'">'.$area_heading.'</a></li><li class="active">'.$page->heading.'</li>';

  	$preachers = \Crockenhill\Sermon::select('preacher')->distinct()->orderBy('preacher', 'asc')->get();
  	// Count number of sermons by each preacher
  	$preacher_array = array();
  	foreach ($preachers as $preacher) {
  		$count = \Crockenhill\Sermon::where('preacher', $preacher->preacher)->count();
  		$preacher_array[$preacher->preacher] = array($count, $preacher->preacher);
  	}
  	arsort($preacher_array);

  	return view('sermons.preachers', array(
      'slug'        => $slug,
      'heading'     => $page->heading,		    
      'description' => '<meta name="description" content="'.$page->description.'">',
      'breadcrumbs' => $breadcrumbs,
      'content'			=> $page->body,
      'preachers'		=> $preacher_array
  ));
	}

	public function getPreacher($preacher)
	{
		$preacher_name = str_replace('-', ' ', \Illuminate\Support\Str::title($preacher));
		$area = 'sermons';
		$area_heading = \Illuminate\Support\Str::title($area);
  	$breadcrumbs = '<li><a href="/'.$area.'">'.$area_heading.'</a></li><li class="active">'.$preacher_name.'</li>';
  	$sermons = \Crockenhill\Sermon::where('preacher', $preacher_name)->orderBy('date')->get();

  	return view('sermons.preacher', array(
      'slug'        => '',
      'heading'     => $preacher_name,		    
      'description' => '<meta name="description" content="Sermons by '.$preacher_name.'">',
      'breadcrumbs' => $breadcrumbs,
      'content'			=> '',
      'sermons'			=> $sermons
  ));
	}

	public function getSerieses()
	{
		$slug = 'series';
		$page = \Crockenhill\Page::where('slug', $slug)->first();
		$area = $page->area;
		$area_heading = \Illuminate\Support\Str::title($page->area);
      	$breadcrumbs = '<li><a href="/'.$page->area.'">'.$area_heading.'</a></li><li class="active">'.$page->heading.'</li>';

      	$series = \Crockenhill\Sermon::select('series')->distinct()->orderBy('date')->get();

      	return view('sermons.serieses', array(
	        'slug'        => $slug,
	        'heading'     => $page->heading,		    
	        'description' => '<meta name="description" content="'.$page->description.'">',
	        'breadcrumbs' => $breadcrumbs,
	        'content'			=> $page->body,
	        'series'		=> $series
	    ));
	}

	public function getSeries($series)
	{
		$series_name = str_replace('-', ' ', \Illuminate\Support\Str::title($series));
		$area = 'sermons';
		$area_heading = \Illuminate\Support\Str::title($area);
      	$breadcrumbs = '<li><a href="/'.$area.'">'.$area_heading.'</a></li><li class="active">'.$series_name.'</li>';
      	$sermons = \Crockenhill\Sermon::where('series', $series_name)->orderBy('date')->get();

      	return view('sermons.series', array(
	        'slug'        => '',
	        'heading'     => $series_name,		    
	        'description' => '<meta name="description" content="Sermons by '.$series_name.'">',
	        'breadcrumbs' => $breadcrumbs,
	        'content'			=> '',
	        'sermons'			=> $sermons
	    ));
	}

}