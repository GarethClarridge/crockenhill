<?php

namespace Crockenhill\Http\Controllers;

use Crockenhill\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Owenoj\LaravelGetId3\GetId3;
use Crockenhill\Sermon;
use Crockenhill\Page;
use Illuminate\Support\Facades\DB; // Added for DB facade
use Illuminate\Support\Facades\Storage; // Added for Storage facade
use Crockenhill\Http\Requests\StoreSermonRequest; // Added for Form Request

class SermonController extends Controller
{

  /**
   * Display a listing of the resource.
   *
   * @return Response
   */
  public function index()
  {
    $distinct_dates = \Crockenhill\Sermon::select('date')
                                ->distinct()
                                ->orderBy('date', 'desc')
                                ->limit(6)
                                ->pluck('date');

    if ($distinct_dates->isNotEmpty()) {
        $latest_sermons = \Crockenhill\Sermon::whereIn('date', $distinct_dates)
                         ->orderBy('date', 'desc')
                         ->orderBy('service', 'asc')
                         ->get()
                         ->groupBy(function ($sermon) {
                             // Assuming 'date' is a Carbon instance or date string 'Y-m-d'
                             return $sermon->date instanceof \Carbon\Carbon ? $sermon->date->format('Y-m-d') : $sermon->date;
                         });
    } else {
        $latest_sermons = collect();
    }

    return view('sermons.index', array(
      'latest_sermons' => $latest_sermons
    ));
  }

  public function getAll()
  {
    $sermons = \Crockenhill\Sermon::orderBy('date', 'desc')
                     ->orderBy('service', 'asc')
                     ->get()
                     ->groupBy(function ($sermon) {
                         return $sermon->date instanceof \Carbon\Carbon ? $sermon->date->format('Y-m-d') : $sermon->date;
                     });

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
  public function store(StoreSermonRequest $request) // Changed Request to StoreSermonRequest
  {
    // Gate check removed, handled by StoreSermonRequest::authorize()

    // The request data is already validated here.
    // You can get validated data using $request->validated();

    $path = null;
    if ($request->hasFile('file') && $request->file('file')->isValid()) {
        $file = $request->file('file');
        // Store the file in 'storage/app/public/sermons' with a unique name
        $path = Storage::disk('public')->putFile('sermons', $file);
        if (!$path) {
            // Handle error if file storage failed
            return redirect()->back()->with('error', 'File upload failed.');
        }
    } else {
        // Handle error if file is not present or invalid
         return redirect()->back()->with('error', 'No valid file uploaded.');
    }

    $pointsData = [];
    for ($p = 1; $p < 7; $p++) {
        if ($request->filled("point-{$p}")) {
            $mainPoint = $request->input("point-{$p}");
            $subPoints = [];
            for ($i = 1; $i < 6; $i++) {
                if ($request->filled("sub-point-{$p}-{$i}")) {
                    $subPoints[] = $request->input("sub-point-{$p}-{$i}");
                }
            }
            // Only add if main point has content, or if sub_points have content even if main is empty (adjust as needed)
            if (!empty($mainPoint) || !empty($subPoints)) {
                 $pointsData[] = ['point' => $mainPoint ?: '', 'sub_points' => $subPoints];
            }
        }
    }

    $sermon = new \Crockenhill\Sermon;
    // Use $request->input() or $request->validated() for other fields.
    // $request->validated() is preferred if all fields are in the rules.
    $validatedData = $request->validated();

    $sermon->title      = $validatedData['title'];
    $sermon->filename   = $path; // filename comes from storage, not direct validation
    $sermon->date       = $validatedData['date'];
    $sermon->service    = $validatedData['service'];
    $sermon->slug       = Str::slug($validatedData['title']); // Use validated title for slug
    $sermon->series     = $validatedData['series'] ?? null; // Handle nullable
    $sermon->reference  = $validatedData['reference'] ?? null; // Handle nullable
    $sermon->preacher   = $validatedData['preacher'];
    // Sermon model's $casts property will handle encoding $pointsData to JSON
    $sermon->points     = !empty($pointsData) ? $pointsData : null;

    $sermon->save();

    return redirect()->route('sermonIndex')->with('message', '"' . $sermon->title . '" successfully uploaded!');
  }

  /**
   * Display the specified resource.
   *
   * @param  int  $id
   * @return Response
   */
  public function show($year, $month, $slug)
  {
    $sermon = $this->findSermonOrFail((int)$year, (int)$month, $slug);
    $heading = $sermon->title;

    // Breadcrumbs removed
    // Example of how it might be handled in view or view composer:
    // $breadcrumbs = [
    //   ['url' => route('sermonIndex'), 'title' => 'Sermons'],
    // ];
    // if (isset($sermon->series) && $sermon->series !== '') {
    //   $breadcrumbs[] = ['url' => route('sermonSeries', Str::slug($sermon->series)), 'title' => $sermon->series];
    // }
    // $breadcrumbs[] = ['title' => $sermon->title, 'active' => true];

    return view('sermons.sermon', array(
      'slug'        => $slug,
      'heading'     => $heading,
      'description' => '<meta name="description" content="' . $sermon->title . ': a sermon preached at Crockenhill Baptist Church.">', // Used $sermon->title instead of $sermon->heading
      // 'breadcrumbs' => $breadcrumbs, // Removed
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

    $sermon = $this->findSermonOrFail((int)$year, (int)$month, $slug);
    $series = array_unique(\Crockenhill\Sermon::pluck('series')->all()); // Used FQCN for Sermon

    // Breadcrumbs removed

    return view('sermons.edit', array(
      'sermon'        => $sermon,
      'series'        => $series,
      'heading'       => 'Edit this sermon',
      'description'   => '<meta name="description" content="Edit this sermon.">',
      // 'breadcrumbs'   => $breadcrumbs, // Removed
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

    $sermon = $this->findSermonOrFail((int)$year, (int)$month, $slug);
    $sermon->title      = trim($request->input('title'));
    $sermon->date       = $request->input('date');
    $sermon->service    = $request->input('service');
    $sermon->slug       = Str::slug($request->input('title'));
    $sermon->series     = trim($request->input('series'));
    $sermon->reference  = trim($request->input('reference'));
    $sermon->preacher   = trim($request->input('preacher'));
    $sermon->points     = trim($request->input('points')); // Points handling in update is not changed to JSON as per subtask note.
    $sermon->save();

    return redirect()->route('sermonIndex')->with('message', '"' . $request->input('title') . '" successfully updated!');
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

    $sermon = $this->findSermonOrFail((int)$year, (int)$month, $slug);
    $sermon->delete();

    return redirect()->route('sermonIndex')->with('message', 'Sermon successfully deleted!');
  }

  public function getPreachers()
  {
    $page = \Crockenhill\Page::where('slug', 'preachers')->first();

    $preachers_with_counts = \Crockenhill\Sermon::select('preacher', \Illuminate\Support\Facades\DB::raw('COUNT(*) as sermons_count'))
                                   ->groupBy('preacher')
                                   ->orderByDesc('sermons_count')
                                   ->orderBy('preacher', 'asc')
                                   ->get();

    $preacher_array = [];
    foreach ($preachers_with_counts as $preacher_data) {
        $preacher_array[$preacher_data->preacher] = [$preacher_data->sermons_count, $preacher_data->preacher];
    }

    return view('sermons.preachers', array(
      'preachers'   => $preacher_array,
      'heading'       => 'Preachers',
      'description'   => '<meta name="description" content="Preachers at Crockenhill Baptist Church.">',
      'content'       => $page ? $page->body : '', // Add a check for $page
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

    if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
        return redirect()->back()->with('error', 'No valid file uploaded.');
    }

    $file = $request->file('file');

    // ID3 - get info from the temporary uploaded file
    $track = new \Owenoj\LaravelGetId3\GetId3($file); // Pass the UploadedFile object
    $info = $track->extractInfo();

    //File handling - extract info from original name BEFORE storing with unique name
    $originalClientName = $file->getClientOriginalName();
    $originalFilenameBase = pathinfo($originalClientName, PATHINFO_FILENAME);


    $date = Str::beforeLast($originalFilenameBase, '-');
    if (Str::afterLast($originalFilenameBase, '-') === 'am') {
      $service = 'morning';
    } elseif (Str::afterLast($originalFilenameBase, '-') === 'pm') {
      $service = 'evening';
    };

    //Reference
    $reference = '';
    if (isset($info['comments']['comment'][0])) {
      $reference = $info['comments']['comment'][0];
    }

    // Now, store the file with a unique name
    $path = Storage::disk('public')->putFile('sermons', $file);
    if (!$path) {
        // Handle error if file storage failed
        return redirect()->back()->with('error', 'File upload failed during storage.');
    }

    $sermon = new \Crockenhill\Sermon;
    $sermon->title      = $track->getTitle();
    $sermon->filename   = $path; // Use the new path
    $sermon->date       = $date;
    $sermon->service    = $service;
    $sermon->slug       = Str::slug($track->getTitle());
    $sermon->series     = $track->getAlbum();
    $sermon->reference  = $reference;
    $sermon->preacher   = $track->getArtist();
    $sermon->save();

    return redirect()->route('sermonIndex')->with('message', $track->getTitle() . ' successfully posted!');
  }

  private function findSermonOrFail(int $year, int $month, string $slug): \Crockenhill\Sermon
  {
      $sermon = \Crockenhill\Sermon::where('slug', $slug)
          ->whereYear('date', $year)
          ->whereMonth('date', $month)
          ->first();

      if (!$sermon) {
          abort(404, 'Sermon not found.');
      }
      return $sermon;
  }
}
