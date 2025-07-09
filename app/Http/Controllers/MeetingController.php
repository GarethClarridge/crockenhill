<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMeetingRequest;
use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Meeting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;

class MeetingController extends Controller
{
    /**
     * Instantiate a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth')->except(['show']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse
     */
    public function index()
    {
        $this->authorize('viewAny', Meeting::class);
        $meetings = Meeting::orderBy('meeting_date', 'desc')->get();

        return View::make('meetings.index', ['meetings' => $meetings]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse
     */
    public function create()
    {
        $this->authorize('create', Meeting::class);

        return View::make('meetings.create', ['heading' => 'Create a meeting']);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreMeetingRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        
        $meeting = Meeting::create($validated);

        Session::flash('message', 'Meeting "' . $meeting->slug . '" successfully created!');

        return Redirect::to('/church/members/meetings');
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function show(Meeting $meeting)
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
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse
     */
    public function edit(Meeting $meeting)
    {
        $this->authorize('update', $meeting);

        Session::put('backUrl', url()->previous());

        $heading = 'Edit meeting';

        return View::make('meetings.edit', [
            'meeting' => $meeting,
            'heading' => $heading,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateMeetingRequest $request, Meeting $meeting): RedirectResponse
    {
        $validated = $request->validated();
        
        $meeting->update($validated);

        $backUrl = Session::get('backUrl');
        Session::forget('backUrl');

        return ($backUrl && $backUrl !== url()->previous())
          ? Redirect::to($backUrl)->with('message', 'Meeting "' . $meeting->slug . '" successfully updated!')
          : Redirect::to('/church/members/meetings')->with('message', 'Meeting "' . $meeting->slug . '" successfully updated!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Meeting $meeting): RedirectResponse
    {
        $this->authorize('delete', $meeting);

        $meetingSlug = $meeting->slug;
        $meeting->delete();

        Session::flash('message', 'Meeting "' . $meetingSlug . '" successfully deleted!');

        return Redirect::to('/church/members/meetings');
    }
}
