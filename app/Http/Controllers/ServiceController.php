<?php

namespace Crockenhill\Http\Controllers;

use Crockenhill\Jobs\ExtractSermonAudioFromVideo;
use Crockenhill\Service;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ServiceController extends Controller
{
  /**
   * Display a listing of the resource.
   */
  public function index()
  {
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    return view('services.index', array(
      'services' => Service::orderBy('date', 'desc')->get(),
    ));
  }

  /**
   * Show the form for creating a new resource.
   */
  public function create()
  {
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    return view('services.create');
  }

  /**
   * Store a newly created resource in storage.
   */
  public function store(Request $request)
  {
    if (Gate::denies('edit-sermons')) {
      abort(403);
    }

    //File handling
    $file = $request->file('file');
    $name = $file->getClientOriginalName();
    $extension = $file->getClientOriginalExtension();
    $path = $file->storeAs('services/' . $name);

    $service = Service::create([
      'date'         => $request->date,
      'type'         => 'morning',
      'video'        => $path,
      'audio'        => $path,
    ]);

    $audio = FFMpeg::fromDisk('local')
      ->open($path)
      ->export()
      ->toDisk('local')
      ->inFormat(new \FFMpeg\Format\Audio\Mp3())
      ->save('services/audio/' . $name . '.mp3');

    // Scene detection using FFMpeg
    $outputDirectory = 'services/scenes/' . $name;
    $sceneFilter = 'select=gt(scene\,0.1)';

    FFMpeg::fromDisk('local')
      ->open($path)
      ->export()
      ->toDisk('local')
      ->addFilter('-vf', $sceneFilter)
      ->addFilter(['-vsync', 'vfr'])
      ->save($outputDirectory . '/scene-%03d.png');

    if ($file->isValid()) {
      return redirect()->route('services.index')->with('message', 'Video successfully uploaded at ' . $path . '!');
    } else {
      return redirect()->route('services.index')->with('message', 'Video upload failed!');
    }
  }

  /**
   * Display the specified resource.
   */
  public function show(Service $service)
  {
    //
  }

  /**
   * Show the form for editing the specified resource.
   */
  public function edit(Service $service)
  {
    //
  }

  /**
   * Update the specified resource in storage.
   */
  public function update(Request $request, Service $service)
  {
    //
  }

  /**
   * Remove the specified resource from storage.
   */
  public function destroy(Service $service)
  {
    //
  }
}
