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
    $last_6_weeks = \Crockenhill\Sermon::orderBy('date', 'desc')
                    ->take(6)
                    ->pluck('date');

    $latest_sermons = [];

    foreach ($last_6_weeks as $week) {
      $latest_sermons[$week] = \Crockenhill\Sermon::where('date', $week)->get();
    }

		return view('sermons.index', array(
	    'latest_sermons' => $latest_sermons
		));
	}

  public function getAll()
  {
    $weeks = \Crockenhill\Sermon::orderBy('date', 'desc')
                      ->pluck('date');

    $sermons = [];

    foreach ($weeks as $week) {
      $sermons[$week] = \Crockenhill\Sermon::where('date', $week)->get();
    }

    return view('sermons.all', array(
			'sermons' => $sermons,
    ));
  }

	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
    if (\Gate::denies('edit-sermons')) {
      abort(403);
    }

		$series = array_unique(\Crockenhill\Sermon::pluck('series')->all());

		return view('sermons.create', array(
      'series' => $series,
  	));
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store()
	{
    if (\Gate::denies('edit-sermons')) {
      abort(403);
    }

		$file = \Input::file('file');
    $file->move('media/sermons', $file->getClientOriginalName());
    $filename = substr($file->getClientOriginalName(), 0, -4);

		// Points
		$points = '<ol>';
		for ($p=1; $p < 7; $p++) {
			if (\Input::get('point-'.$p) !== '') {
				$points .= '<li class="h4">'.\Input::get('point-'.$p).'</li>';
			}
			for ($i=1; $i < 6; $i++) {
				if (\Input::get('sub-point-'.$p.'-'.$i) !== '') {
					if ($i == 1) {
						$points .= '<ul>';
						$points .= '<li>'.\Input::get('sub-point-'.$p.'-'.$i).'</li></ul>';
					} else {
						$points = substr($points, 0, -5);
						$points .= '<li>'.\Input::get('sub-point-'.$p.'-'.$i).'</li></ul>';
					}
				}
			}
		}
		$points .= '</ol>';

    $sermon = new \Crockenhill\Sermon;
    $sermon->title      = trim(\Input::get('title'));
    $sermon->filename   = $filename;
    $sermon->date       = \Input::get('date');
    $sermon->service    = \Input::get('service');
    $sermon->slug       = \Illuminate\Support\Str::slug(\Input::get('title'));
    $sermon->series     = trim(\Input::get('series'));
    $sermon->reference  = trim(\Input::get('reference'));
    $sermon->preacher   = trim(\Input::get('preacher'));
		$sermon->points			= trim($points);
    $sermon->save();

    return redirect('sermons')->with('message', '"'.\Input::get('title').'" successfully uploaded!');
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($year, $month, $slug)
	{
		$sermon = \Crockenhill\Sermon::where('slug', $slug)
                                    ->whereBetween('date', array($year.'-'.$month.'-01', $year.'-'.$month.'-31'))
                                    ->first();
   	$heading = $sermon->title;

		if (isset($sermon->series) && $sermon->series !== '') {
			$breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
													<li><a href="series/'.$sermon->series.'">'.$sermon->series.'</a></li>
													<li class="active">'.$sermon->title.'</li>';
		} else {
			$breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
													<li class="active">'.$sermon->title.'</li>';
		}

  	return view('sermons.sermon', array(
	    'slug'        => $slug,
	    'heading'     => $heading,
	    'description' => '<meta name="description" content="'.$sermon->heading.': a sermon preached at Crockenhill Baptist Church.">',
	    'breadcrumbs' => $breadcrumbs,
	    'content'			=> '',
	    'sermon' 			=> $sermon
		));
	}

	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit($year, $month, $slug)
	{
    if (\Gate::denies('edit-sermons')) {
      abort(403);
    }

    $sermon = \Crockenhill\Sermon::where('slug', $slug)
                                    ->whereBetween('date', array($year.'-'.$month.'-01', $year.'-'.$month.'-31'))
                                    ->first();
    $series = array_unique(\Crockenhill\Sermon::pluck('series')->all());

		if (isset($sermon->series) && $sermon->series !== '') {
			$breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
													<li><a href="series/'.$sermon->series.'">'.$sermon->series.'</a></li>
													<li><a href="/sermons/'.$year.'/'.$month.'/'.$slug.'">'.$sermon->title.'</a></li>
													<li class="active">Edit</li>';
		} else {
			$breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
													<li><a href="/sermons/'.$year.'/'.$month.'/'.$slug.'">'.$sermon->title.'</a></li>
													<li class="active">Edit</li>';
		}


    return view('sermons.edit', array(
      'sermon'        => $sermon,
      'series'        => $series,
      'heading'       => 'Edit this sermon',
      'description'   => '<meta name="description" content="Edit this sermon.">',
      'breadcrumbs'   => $breadcrumbs,
      'content'       => '',
    ));
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update($year, $month, $slug)
	{
    if (\Gate::denies('edit-sermons')) {
      abort(403);
    }

    $sermon = \Crockenhill\Sermon::where('slug', $slug)
                                    ->whereBetween('date', array($year.'-'.$month.'-01', $year.'-'.$month.'-31'))
                                    ->first();
    $sermon->title      = trim(\Input::get('title'));
    $sermon->date       = \Input::get('date');
    $sermon->slug       = \Illuminate\Support\Str::slug(\Input::get('title'));
    $sermon->series     = trim(\Input::get('series'));
    $sermon->reference  = trim(\Input::get('reference'));
    $sermon->preacher   = trim(\Input::get('preacher'));
		$sermon->points			= trim(\Input::get('points'));
    $sermon->save();

    return redirect('sermons/'.$year.'/'.$month.'/'.$slug)->with('message', '"'.\Input::get('title').'" successfully updated!');
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($year, $month, $slug)
	{
    if (\Gate::denies('edit-sermons')) {
      abort(403);
    }

    $sermon = \Crockenhill\Sermon::where('slug', $slug)
                                    ->whereBetween('date', array($year.'-'.$month.'-01', $year.'-'.$month.'-31'))
                                    ->first();
    $sermon->delete();

    return redirect('sermons')->with('message', 'Sermon successfully deleted!');;
	}

	public function getPreachers()
	{
		$slug = 'preachers';
		$page = \Crockenhill\Page::where('slug', $slug)->first();

  	$preachers = \Crockenhill\Sermon::select('preacher')->distinct()->orderBy('preacher', 'asc')->get();
  	// Count number of sermons by each preacher
  	$preacher_array = array();
  	foreach ($preachers as $preacher) {
  		$count = \Crockenhill\Sermon::where('preacher', $preacher->preacher)->count();
  		$preacher_array[$preacher->preacher] = array($count, $preacher->preacher);
  	}
  	arsort($preacher_array);

  	return view('sermons.preachers', array(
      'preachers'		=> $preacher_array
  ));
	}

	public function getPreacher($preacher)
	{
		$preacher_name = str_replace('-', ' ', \Illuminate\Support\Str::title($preacher));
  	$sermons = \Crockenhill\Sermon::where('preacher', $preacher_name)
                  ->orderBy('date', 'desc')
                  ->paginate(8);

  	return view('sermons.preacher', array(
      'sermons'			=> $sermons
  ));
	}

	public function getSerieses()
	{
  	$series = \Crockenhill\Sermon::select('series')->distinct()->get();

  	return view('sermons.serieses', array(
        'series'		=> $series
  ));
	}

	public function getSeries($series)
	{
		$series_name = str_replace('-', ' ', \Illuminate\Support\Str::title($series));
  	$sermons = \Crockenhill\Sermon::where('series', $series_name)
                  ->orderBy('date', 'desc')
                  ->paginate(8);

  	return view('sermons.series', array(
          'sermons'			=> $sermons
  	));
	}

}
