<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MeetingController extends Controller
{
    /**
     * Instantiate a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth')->except(['index', 'show']);
        $this->middleware('admin')->except(['index', 'show']);
    }

  /**
   * Display a listing of the resource.
   *
   * @return Response
   */
  public function index()
  {
    $meetings = \App\Models\Meeting::all();
    return view('meetings.index', compact('meetings'));
  }


  /**
   * Show the form for creating a new resource.
   *
   * @return Response
   */
  public function create()
  {
    return view('meetings.create');
  }


  /**
   * Store a newly created resource in storage.
   *
   * @return Response
   */
  public function store(Request $request)
  {
    $validatedData = $request->validate([
      'slug' => 'required|unique:meetings|max:255',
      'type' => 'required|max:255',
      'day' => 'nullable|max:255',
      'location' => 'nullable|max:255',
      'who' => 'nullable|max:255',
      'pictures' => 'boolean',
    ]);

    \App\Models\Meeting::create($validatedData);

    return redirect()->route('meetings.index')->with('success', 'Meeting created successfully.');
  }


  /**
   * Display the specified resource.
   *
   * @param  \App\Models\Meeting  $meeting
   * @return \Illuminate\Http\Response
   */
  public function show(\App\Models\Meeting $meeting)
  {
    // Photos logic might need adjustment based on how pictures are stored and accessed.
    // Assuming 'pictures' column stores a boolean and actual picture paths are derived or stored elsewhere.
    // For now, simplifying the photo logic.
    $photos = [];
    if ($meeting->pictures) {
      // This logic is potentially problematic and might need a more robust solution.
      // For example, storing image paths in the database or using a dedicated media library.
      // Temporarily commenting out the scandir logic as it might not work in all environments / setups.
      // if (is_dir(public_path('images/meetings/' . $meeting->slug))) {
      //   $filelist = scandir(public_path('images/meetings/' . $meeting->slug));
      //   $photos = array_slice($filelist, 2); // Remove . and ..
      // }
    }

    return view('meetings.show', compact('meeting', 'photos'));
  }


  /**
   * Show the form for editing the specified resource.
   *
   * @param  int  $id
   * @return Response
   */
  public function edit(\App\Models\Meeting $meeting)
  {
    return view('meetings.edit', compact('meeting'));
  }


  /**
   * Update the specified resource in storage.
   *
   * @param  int  $id
   * @return Response
   */
  public function update(Request $request, \App\Models\Meeting $meeting)
  {
    $validatedData = $request->validate([
      'slug' => 'required|max:255|unique:meetings,slug,' . $meeting->id,
      'type' => 'required|max:255',
      'day' => 'nullable|max:255',
      'location' => 'nullable|max:255',
      'who' => 'nullable|max:255',
      'pictures' => 'boolean',
    ]);

    $meeting->update($validatedData);

    return redirect()->route('meetings.index')->with('success', 'Meeting updated successfully.');
  }


  /**
   * Remove the specified resource from storage.
   *
   * @param  int  $id
   * @return Response
   */
  public function destroy(\App\Models\Meeting $meeting)
  {
    $meeting->delete();
    return redirect()->route('meetings.index')->with('success', 'Meeting deleted successfully.');
  }
}
