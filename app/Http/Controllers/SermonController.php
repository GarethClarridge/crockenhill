<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonService;
use App\Http\Requests\PostSermonRequest;
use App\Http\Requests\StoreSermonRequest;
use App\Http\Requests\UpdateSermonRequest;
use App\Models\Page;
use App\Models\Sermon; // Added for DB facade
use Illuminate\Http\RedirectResponse; // Added for Storage facade
use Illuminate\Http\Request; // Added for Form Request
use Illuminate\Support\Carbon; // Added for Update Form Request
use Illuminate\Support\Facades\DB; // Added for Post Form Request
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SermonController extends Controller
{
  /**
   * Display a listing of the resource.
   */
  public function index(): View
  {
    $distinct_dates = Sermon::select('date')
      ->distinct()
      ->orderBy('date', 'desc')
      ->limit(6)
      ->pluck('date');

    if ($distinct_dates->isNotEmpty()) {
      $latest_sermons = Sermon::whereIn('date', $distinct_dates)
        ->orderBy('date', 'desc')
        ->orderBy('service', 'asc')
        ->get()
        ->groupBy(fn($sermon) => $sermon->date->format('Y-m-d'));
    } else {
      $latest_sermons = collect();
    }

    return view('sermons.index', [
      'latest_sermons' => $latest_sermons,
    ]);
  }

  public function getAll()
  {
    $sermons = Sermon::orderBy('date', 'desc')
      ->orderBy('service', 'asc')
      ->get()
      ->groupBy(function ($sermon) {
        return $sermon->date->format('Y-m-d');
      });

    return view('sermons.all', [
      'sermons' => $sermons,
    ]);
  }

  /**
   * Show the form for creating a new resource.
   */
  public function create(): View
  {
    $this->authorize('create', Sermon::class);

    $series = array_unique(Sermon::pluck('series')->all());

    return view('sermons.create', [
      'series' => $series,
      'heading' => 'Upload sermon',
    ]);
  }

  /**
   * Store a newly created resource in storage.
   */
  public function store(StoreSermonRequest $request): RedirectResponse
  {
    // Gate check removed, handled by StoreSermonRequest::authorize()

    // The request data is already validated here.
    // You can get validated data using $request->validated();

    $path = null;
    if ($request->hasFile('file') && $request->file('file')->isValid()) {
      $file = $request->file('file');
      // Store the file in 'storage/app/public/sermons' with a unique name
      $path = Storage::disk('public')->putFile('sermons', $file);
      if (! $path) {
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
        if (! empty($mainPoint) || ! empty($subPoints)) {
          $pointsData[] = ['point' => $mainPoint ?: '', 'sub_points' => $subPoints];
        }
      }
    }

    $sermon = new \App\Models\Sermon;
    // Use $request->input() or $request->validated() for other fields.
    // $request->validated() is preferred if all fields are in the rules.
    $validatedData = $request->validated();

    $sermon->title = $validatedData['title'];
    $sermon->filename = $path; // filename comes from storage, not direct validation
    $sermon->date = Carbon::parse($validatedData['date']);
    $sermon->service = SermonService::from($validatedData['service']);
    $sermon->slug = Str::slug($validatedData['title']); // Use validated title for slug
    $sermon->series = $validatedData['series'] ?? null; // Handle nullable
    $sermon->reference = $validatedData['reference'] ?? null; // Handle nullable
    $sermon->preacher = $validatedData['preacher'];
    // Sermon model's $casts property will handle encoding $pointsData to JSON
    $sermon->points = ! empty($pointsData) ? $pointsData : null;

    $sermon->save();

    return redirect()->route('sermonIndex')->with('message', '"' . $sermon->title . '" successfully uploaded!');
  }

  /**
   * Display the specified resource.
   */
  public function show(Sermon $sermon): View
  {
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

    return view('sermons.sermon', [
      'slug' => $sermon->slug,
      'heading' => $heading,
      'description' => '<meta name="description" content="' . $sermon->title . ': a sermon preached at Crockenhill Baptist Church.">', // Used $sermon->title instead of $sermon->heading
      // 'breadcrumbs' => $breadcrumbs, // Removed
      'content' => '',
      'sermon' => $sermon,
    ]);
  }

  /**
   * Show the form for editing the specified resource.
   */
  public function edit(Sermon $sermon): View
  {
    $this->authorize('update', $sermon);

    $series = array_unique(\App\Models\Sermon::pluck('series')->all()); // Used FQCN for Sermon

    // Breadcrumbs removed

    return view('sermons.edit', [
      'sermon' => $sermon,
      'series' => $series,
      'heading' => 'Edit this sermon',
      'description' => '<meta name="description" content="Edit this sermon.">',
      // 'breadcrumbs'   => $breadcrumbs, // Removed
      'content' => '',
    ]);
  }

  /**
   * Update the specified resource in storage.
   */
  public function update(Sermon $sermon, UpdateSermonRequest $request): RedirectResponse
  {
    // Gate check removed, handled by UpdateSermonRequest

    $validatedData = $request->validated();

    $sermon->title = $validatedData['title'];
    $sermon->date = Carbon::parse($validatedData['date']);
    $sermon->service = SermonService::from($validatedData['service']);
    $sermon->slug = Str::slug($validatedData['title']); // Update slug if title changes
    $sermon->series = $validatedData['series'] ?? null;
    $sermon->reference = $validatedData['reference'] ?? null;
    $sermon->preacher = $validatedData['preacher'];

    // The 'points' attribute from $validatedData will be a JSON string or null.
    // The Sermon model's $casts property will automatically convert this JSON string
    // to an array when $sermon->points is assigned and saved.
    // Update points only if the key exists in validated data (meaning it was submitted and passed validation)
    if (array_key_exists('points', $validatedData)) {
      // Explicitly decode JSON string to array here.
      // If $validatedData['points'] is null, json_decode(null, true) is null.
      // If $validatedData['points'] is a valid JSON string, it's decoded to an array.
      $sermon->points = $validatedData['points'] ? json_decode($validatedData['points'], true) : null;
    }

    if ($sermon->save()) {
      return redirect()->route('sermonIndex')->with('message', '"' . $sermon->title . '" successfully updated!');
    } else {
      // Log the failure or add more specific error handling
      return redirect()->back()->withInput()->with('error', 'There was a problem saving the sermon. Please try again.');
    }
  }

  /**
   * Remove the specified resource from storage.
   */
  public function destroy(Sermon $sermon): RedirectResponse
  {
    $this->authorize('delete', $sermon);

    $sermon->delete();

    return redirect()->route('sermonIndex')->with('message', 'Sermon successfully deleted!');
  }

  public function getPreachers(): View
  {
    $page = Page::where('slug', 'preachers')->first();

    $preachers_with_counts = Sermon::select('preacher', DB::raw('COUNT(*) as sermons_count'))
      ->groupBy('preacher')
      ->orderByDesc('sermons_count')
      ->orderBy('preacher', 'asc')
      ->get();

    $preacher_array = [];
    foreach ($preachers_with_counts as $preacher_data) {
      /** @phpstan-ignore-next-line */
      $preacher_array[$preacher_data->preacher] = [$preacher_data->sermons_count, $preacher_data->preacher];
    }

    return view('sermons.preachers', [
      'preachers' => $preacher_array,
      'heading' => 'Preachers',
      'description' => '<meta name="description" content="Preachers at Crockenhill Baptist Church.">',
      'content' => $page ? $page->body : '', // Add a check for $page
    ]);
  }

  public function getPreacher($preacher)
  {
    $preacher_name = str_replace('-', ' ', Str::title($preacher));
    $sermons = Sermon::where('preacher', $preacher_name)
      ->orderBy('date', 'desc')
      ->get();

    return view('sermons.preacher', [
      'sermons' => $sermons,
    ]);
  }

  public function getSerieses()
  {
    $series = Sermon::select('series')->distinct()->get();

    return view('sermons.serieses', [
      'series' => $series,
    ]);
  }

  public function getSeries($series)
  {
    $series_name = str_replace('-', ' ', Str::title($series));
    $sermons = Sermon::where('series', $series_name)
      ->orderBy('date', 'desc')
      ->get();

    return view('sermons.series', [
      'sermons' => $sermons,
    ]);
  }

  public function getService($service)
  {
    $sermons = Sermon::where('service', $service)
      ->orderBy('date', 'desc')
      ->get();

    return view('sermons.service', [
      'sermons' => $sermons,
    ]);
  }

  /**
   * Show the simple upload for creating a new resource.
   */
  public function upload(): View
  {
    $this->authorize('create', Sermon::class);

    return view('sermons.upload', [
      'heading' => 'Upload sermon',
    ]);
  }

  /**
   * Post a newly created resource in storage.
   */
  public function post(PostSermonRequest $request): RedirectResponse
  {
    // Manual Gate check removed
    // Manual file validation check removed

    // $request is now an instance of PostSermonRequest, validation has passed.
    $file = $request->file('file'); // This is safe to call now.

    // ID3 - get info from the temporary uploaded file
    $track = new \Owenoj\LaravelGetId3\GetId3($file); // Pass the UploadedFile object
    $info = $track->extractInfo();

    // File handling - extract info from original name BEFORE storing with unique name
    $originalClientName = $file->getClientOriginalName();
    $originalFilenameBase = pathinfo($originalClientName, PATHINFO_FILENAME);

    $date = Str::beforeLast($originalFilenameBase, '-');
    $serviceString = match (Str::afterLast($originalFilenameBase, '-')) {
      'am' => 'morning',
      'pm' => 'evening',
      default => 'other',
    };

    // Reference
    $reference = '';
    if (isset($info['comments']['comment'][0])) {
      $reference = $info['comments']['comment'][0];
    }

    // Now, store the file with a unique name
    $path = Storage::disk('public')->putFile('sermons', $file);
    if (! $path) {
      // Handle error if file storage failed
      return redirect()->back()->with('error', 'File upload failed during storage.');
    }

    $sermon = new \App\Models\Sermon;
    $sermon->title = $track->getTitle();
    $sermon->filename = $path;
    $sermon->date = Carbon::parse($date);
    $sermon->service = SermonService::from($serviceString);
    $sermon->slug = Str::slug($track->getTitle() ?: $originalFilenameBase); // Fallback for slug
    $sermon->series = $track->getAlbum();
    $sermon->reference = $reference;
    $sermon->preacher = $track->getArtist();
    // Points are not handled by this upload method.
    $sermon->save();

    return redirect()->route('sermonIndex')->with('message', ($track->getTitle() ?: 'Sermon') . ' successfully posted!');
  }

  /**
   * Display the specified resource with date validation.
   */
  public function showWithDate(int $year, int $month, Sermon $sermon): View
  {
    // Validate that the sermon's date matches the URL parameters
    if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
      abort(404, 'Sermon not found for the specified date.');
    }

    return $this->show($sermon);
  }

  /**
   * Show the form for editing the specified resource with date validation.
   */
  public function editWithDate(int $year, int $month, Sermon $sermon): View
  {
    // Validate that the sermon's date matches the URL parameters
    if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
      abort(404, 'Sermon not found for the specified date.');
    }

    return $this->edit($sermon);
  }

  /**
   * Update the specified resource in storage with date validation.
   */
  public function updateWithDate(int $year, int $month, Sermon $sermon, UpdateSermonRequest $request): RedirectResponse
  {
    // Validate that the sermon's date matches the URL parameters
    if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
      abort(404, 'Sermon not found for the specified date.');
    }

    return $this->update($sermon, $request);
  }

  /**
   * Remove the specified resource from storage with date validation.
   */
  public function destroyWithDate(int $year, int $month, Sermon $sermon): RedirectResponse
  {
    // Validate that the sermon's date matches the URL parameters
    if ($sermon->date->year !== $year || $sermon->date->month !== $month) {
      abort(404, 'Sermon not found for the specified date.');
    }

    return $this->destroy($sermon);
  }

  private function findSermonOrFail(int $year, int $month, string $slug): \App\Models\Sermon
  {
    $sermon = \App\Models\Sermon::where('slug', $slug)
      ->whereYear('date', $year)
      ->whereMonth('date', $month)
      ->first();

    if (! $sermon) {
      abort(404, 'Sermon not found.');
    }

    return $sermon;
  }
}
