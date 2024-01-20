<?php

namespace Crockenhill\Http\Controllers;

use Crockenhill\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Owenoj\LaravelGetId3\GetId3;
use Crockenhill\Sermon;
use Crockenhill\Page;

class SermonController extends Controller
{

  /**
   * Display a listing of the resource.
   *
   * @return Response
   */
  public function index()
  {
    $last_6_weeks = Sermon::orderBy('date', 'desc')
      ->take(6)
      ->pluck('date');

    $latest_sermons = [];

    foreach ($last_6_weeks as $week) {
      $latest_sermons[$week] = Sermon::where('date', $week)->get();
    }

    return view('sermons.index', array(
      'latest_sermons' => $latest_sermons
    ));
  }

  public function getAll()
  {
    $weeks = Sermon::orderBy('date', 'desc')
      ->pluck('date');

    $sermons = [];

    foreach ($weeks as $week) {
      $sermons[$week] = Sermon::where('date', $week)->get();
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
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    $series = array_unique(Sermon::pluck('series')->all());

    return view('sermons.create', array(
      'series' => $series,
      'heading' => "Upload sermon"
    ));
  }

  /**
   * Store a newly created resource in storage.
   *
   * @return Response
   */
  public function store(Request $request)
  {
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    $file = $request->file('file');
    $file->move('media/sermons', $file->getClientOriginalName());
    $filename = substr($file->getClientOriginalName(), 0, -4);

    // Points
    if ($request->input('point-1') !== NULL) {
      $points = '<ol>';
      for ($p = 1; $p < 7; $p++) {
        if ($request->input('point-' . $p) !== NULL) {
          $points .= '<li class="h4">' . $request->input('point-' . $p) . '</li>';
        }
        for ($i = 1; $i < 6; $i++) {
          if ($request->input('sub-point-' . $p . '-' . $i) !== NULL) {
            if ($i == 1) {
              $points .= '<ul>';
              $points .= '<li>' . $request->input('sub-point-' . $p . '-' . $i) . '</li></ul>';
            } else {
              $points = substr($points, 0, -5);
              $points .= '<li>' . $request->input('sub-point-' . $p . '-' . $i) . '</li></ul>';
            }
          }
        }
      }
      $points .= '</ol>';
    } else {
      $points = NULL;
    }

    $sermon = new Sermon;
    $sermon->title      = trim($request->input('title'));
    $sermon->filename   = $filename;
    $sermon->date       = $request->input('date');
    $sermon->service    = $request->input('service');
    $sermon->slug       = Str::slug($request->input('title'));
    $sermon->series     = trim($request->input('series'));
    $sermon->reference  = trim($request->input('reference'));
    $sermon->preacher   = trim($request->input('preacher'));
    $sermon->points      = trim($points);
    $sermon->save();

    return redirect()->route('sermonIndex')->with('message', '"' . $request->input('title') . '" successfully uploaded!');
  }

  /**
   * Display the specified resource.
   *
   * @param  int  $id
   * @return Response
   */
  public function show($year, $month, $slug)
  {
    $sermon = Sermon::where('slug', $slug)
      ->whereMonth('date', '=', $month)
      ->whereYear('date', '=', $year)
      ->first();
    $heading = $sermon->title;

    if (isset($sermon->series) && $sermon->series !== '') {
      $breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
													<li><a href="series/' . $sermon->series . '">' . $sermon->series . '</a></li>
													<li class="active">' . $sermon->title . '</li>';
    } else {
      $breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
													<li class="active">' . $sermon->title . '</li>';
    }

    return view('sermons.sermon', array(
      'slug'        => $slug,
      'heading'     => $heading,
      'description' => '<meta name="description" content="' . $sermon->heading . ': a sermon preached at Crockenhill Baptist Church.">',
      'breadcrumbs' => $breadcrumbs,
      'content'      => '',
      'sermon'       => $sermon
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
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    $sermon = Sermon::where('slug', $slug)
      ->whereMonth('date', '=', $month)
      ->whereYear('date', '=', $year)
      ->first();
    $series = array_unique(Sermon::pluck('series')->all());

    if (isset($sermon->series) && $sermon->series !== '') {
      $breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
													<li><a href="series/' . $sermon->series . '">' . $sermon->series . '</a></li>
													<li><a href="/christ/sermons/' . $year . '/' . $month . '/' . $slug . '">' . $sermon->title . '</a></li>
													<li class="active">Edit</li>';
    } else {
      $breadcrumbs = '<li><a href="/sermons">Sermons</a></li>
													<li><a href="/christ/sermons/' . $year . '/' . $month . '/' . $slug . '">' . $sermon->title . '</a></li>
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
  public function update($year, $month, $slug, Request $request)
  {
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    $sermon = Sermon::where('slug', $slug)
      ->whereMonth('date', '=', $month)
      ->whereYear('date', '=', $year)
      ->first();
    $sermon->title      = trim($request->input('title'));
    $sermon->date       = $request->input('date');
    $sermon->service    = $request->input('service');
    $sermon->slug       = Str::slug($request->input('title'));
    $sermon->series     = trim($request->input('series'));
    $sermon->reference  = trim($request->input('reference'));
    $sermon->preacher   = trim($request->input('preacher'));
    $sermon->points     = trim($request->input('points'));
    $sermon->save();

    return redirect('christ/sermons/')->with('message', '"' . $request->input('title') . '" successfully updated!');
  }

  /**
   * Remove the specified resource from storage.
   *
   * @param  int  $id
   * @return Response
   */
  public function destroy($year, $month, $slug)
  {
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    $sermon = Sermon::where('slug', $slug)
      ->whereMonth('date', '=', $month)
      ->whereYear('date', '=', $year)
      ->first();
    $sermon->delete();

    return redirect('christ/sermons')->with('message', 'Sermon successfully deleted!');;
  }

  public function getPreachers()
  {
    $page = Page::where('slug', 'preachers')->first();

    $preachers = Sermon::select('preacher')->distinct()
      ->orderBy('preacher', 'asc')
      ->get();
    // Count number of sermons by each preacher
    $preacher_array = array();
    foreach ($preachers as $preacher) {
      $count = Sermon::where('preacher', $preacher->preacher)->count();
      $preacher_array[$preacher->preacher] = array($count, $preacher->preacher);
    }
    arsort($preacher_array);

    return view('sermons.preachers', array(
      'preachers'   => $preacher_array,
      'heading'       => 'Preachers',
      'description'   => '<meta name="description" content="Preachers at Crockenhill Baptist Church.">',
      'content'       => $page->body,
    ));
  }

  public function getPreacher($preacher)
  {
    $preacher_name = str_replace('-', ' ', Str::title($preacher));
    $sermons = Sermon::where('preacher', $preacher_name)
      ->orderBy('date', 'desc')
      ->get();

    return view('sermons.preacher', array(
      'sermons'      => $sermons
    ));
  }

  public function getSerieses()
  {
    $series = Sermon::select('series')->distinct()->get();

    return view('sermons.serieses', array(
      'series'    => $series
    ));
  }

  public function getSeries($series)
  {
    $series_name = str_replace('-', ' ', Str::title($series));
    $sermons = Sermon::where('series', $series_name)
      ->orderBy('date', 'desc')
      ->get();

    return view('sermons.series', array(
      'sermons'      => $sermons
    ));
  }

  public function getService($service)
  {
    $sermons = Sermon::where('service', $service)
      ->orderBy('date', 'desc')
      ->get();

    return view('sermons.service', array(
      'sermons'      => $sermons
    ));
  }


  /**
   * Show the simple upload for creating a new resource.
   *
   * @return Response
   */
  public function upload()
  {
    if (Gate::denies('edit-sermons')) {
      abort(403);
    };

    return view('sermons.upload', array(
      'heading' => "Upload sermon",
    ));
  }

  /**
   * Post a newly created resource in storage.
   *
   * @return Response
   */
  public function post(Request $request)
  {
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    // ID3 
    $track = new GetId3(request()->file('file'));
    $info = $track->extractInfo();

    //File handling
    $file = $request->file('file');
    $file->move('media/sermons', $file->getClientOriginalName());
    $filename = substr($file->getClientOriginalName(), 0, -4);

    $date = Str::beforeLast($filename, '-');
    if (Str::afterLast($filename, '-') === 'am') {
      $service = 'morning';
    } elseif (Str::afterLast($filename, '-') === 'pm') {
      $service = 'evening';
    };

    //Reference
    $reference = '';
    if (isset($info['comments']['comment'][0])) {
      $reference = $info['comments']['comment'][0];
    }

    $sermon = new Sermon;
    $sermon->title      = $track->getTitle();
    $sermon->filename   = $filename;
    $sermon->date       = $date;
    $sermon->service    = $service;
    $sermon->slug       = Str::slug($track->getTitle());
    $sermon->series     = $track->getAlbum();
    $sermon->reference  = $reference;
    $sermon->preacher   = $track->getArtist();
    $sermon->save();

    return redirect()->route('sermonIndex')->with('message', $track->getTitle() . ' successfully posted!');
  }
}
