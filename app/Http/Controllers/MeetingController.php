<?php

namespace App\Http\Controllers;

// use Illuminate\Http\Request; // Replaced by specific Form Requests
use App\Http\Requests\StoreMeetingRequest;
use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Meeting;

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
   * @return \Illuminate\View\View
   */
  public function index()
  {
    $meetings = Meeting::all();
    return view('meetings.index', compact('meetings'));
  }


  /**
   * Show the form for creating a new resource.
   *
   * @return \Illuminate\View\View
   */
  public function create()
  {
    return view('meetings.create');
  }


  /**
   * Store a newly created resource in storage.
   *
   * @param  \App\Http\Requests\StoreMeetingRequest  $request
   * @return \Illuminate\Http\RedirectResponse
   */
  public function store(StoreMeetingRequest $request)
  {
    $validatedData = $request->validated();
    Meeting::create($validatedData);

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
   * @param  \App\Http\Requests\UpdateMeetingRequest  $request
   * @param  \App\Models\Meeting  $meeting
   * @return \Illuminate\Http\RedirectResponse
   */
  public function update(UpdateMeetingRequest $request, Meeting $meeting)
  {
    $validatedData = $request->validated();
    $meeting->update($validatedData);

    return redirect()->route('meetings.index')->with('success', 'Meeting updated successfully.');
  }


  /**
   * Remove the specified resource from storage.
   *
   * @param  \App\Models\Meeting  $meeting
   * @return \Illuminate\Http\RedirectResponse
   */
  public function destroy(Meeting $meeting)
  {
    $meeting->delete();
    return redirect()->route('meetings.index')->with('success', 'Meeting deleted successfully.');
  }
}
