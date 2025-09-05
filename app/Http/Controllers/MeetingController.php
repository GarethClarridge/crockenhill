<?php

declare(strict_types=1);

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
        $this->middleware('auth')->except(['show', 'showCommunityContent']);
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
     */
    public function store(StoreMeetingRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $meeting = Meeting::create($validated);

        Session::flash('message', 'Meeting "'.$meeting->slug.'" successfully created!');

        return Redirect::to('/church/members/meetings');
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function show(Meeting $meeting)
    {
        // Eager load calendar events to avoid N+1 queries
        $meeting->load([
            'calendarEvents' => function ($query) {
                $query->upcoming()->confirmed()->orderBy('start_datetime')->limit(5);
            },
        ]);

        $upcomingEvents = $meeting->calendarEvents;

        // Load past events separately if needed
        $pastEvents = \App\Models\CalendarEvent::where('meeting_slug', $meeting->slug)
            ->past()
            ->confirmed()
            ->orderBy('start_datetime', 'desc')
            ->limit(10)
            ->get();

        // Photos logic might need adjustment based on how pictures are stored and accessed.
        $photos = [];
        if ($meeting->pictures) {
            // Temporarily commenting out the scandir logic as it might not work in all environments / setups.
            // if (is_dir(public_path('images/meetings/' . $meeting->slug))) {
            //   $filelist = scandir(public_path('images/meetings/' . $meeting->slug));
            //   $photos = array_slice($filelist, 2); // Remove . and ..
            // }
        }

        return view('meetings.show', compact('meeting', 'photos', 'upcomingEvents', 'pastEvents'));
    }

    /**
     * Show a meeting or page from the community area.
     */
    public function showCommunityContent(string $slug)
    {
        $meeting = Meeting::where('slug', $slug)->first();

        if ($meeting) {
            // Eager load calendar events
            $meeting->load([
                'calendarEvents' => function ($query) {
                    $query->upcoming()->confirmed()->orderBy('start_datetime')->limit(5);
                },
            ]);

            $upcomingEvents = $meeting->calendarEvents;
            $pastEvents = \App\Models\CalendarEvent::where('meeting_slug', $meeting->slug)
                ->past()
                ->confirmed()
                ->orderBy('start_datetime', 'desc')
                ->limit(10)
                ->get();

            $photos = [];
            if ($meeting->pictures) {
                // Add your photo logic here if needed
            }

            return view('meetings.show', compact('meeting', 'photos', 'upcomingEvents', 'pastEvents'));
        }

        // Check if there's a page in the community area
        $page = \App\Models\Page::where('slug', $slug)
            ->where('area', 'community')
            ->first();

        if ($page) {
            return app(\App\Http\Controllers\PageController::class)
                ->show('community', $slug, app(\League\CommonMark\CommonMarkConverter::class));
        }

        // Neither meeting nor page found
        abort(404);
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
     */
    public function update(UpdateMeetingRequest $request, Meeting $meeting): RedirectResponse
    {
        $validated = $request->validated();

        $meeting->update($validated);

        $backUrl = Session::get('backUrl');
        Session::forget('backUrl');

        return ($backUrl && $backUrl !== url()->previous())
          ? Redirect::to($backUrl)->with('message', 'Meeting "'.$meeting->slug.'" successfully updated!')
          : Redirect::to('/church/members/meetings')->with('message', 'Meeting "'.$meeting->slug.'" successfully updated!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Meeting $meeting): RedirectResponse
    {
        $this->authorize('delete', $meeting);

        $meetingSlug = $meeting->slug;
        $meeting->delete();

        Session::flash('message', 'Meeting "'.$meetingSlug.'" successfully deleted!');

        return Redirect::to('/church/members/meetings');
    }
}
