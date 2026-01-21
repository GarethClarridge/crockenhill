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
        // Eager load page and calendar events to avoid N+1 queries
        $meeting->load([
            'page',
            'calendarEvents' => function ($query) {
                $query->upcoming()->confirmed()->orderBy('start_datetime')->limit(6);
            },
        ]);

        $upcomingEvents = $meeting->calendarEvents;

        // Load past events separately
        $pastEvents = \App\Models\CalendarEvent::where('meeting_slug', $meeting->slug)
            ->past()
            ->confirmed()
            ->orderBy('start_datetime', 'desc')
            ->limit(3)
            ->get();

        // Photos logic
        $photos = [];
        if ($meeting->pictures) {
            $photoDir = public_path('images/meetings/'.$meeting->slug);
            if (is_dir($photoDir)) {
                $filelist = scandir($photoDir);
                $photos = array_slice($filelist, 2); // Remove . and ..
            }
        }

        // Variables for the layout
        $page = $meeting->page;
        $heading = $page->heading ?? $meeting->heading;
        $headingpicture = $page?->heading_image_url;
        $content = $page?->body; // Page content shown before meeting details
        $area = 'community';
        $slug = $meeting->slug;

        return view('meetings.show', [
            'meeting' => $meeting,
            'page' => $page,
            'photos' => $photos,
            'upcomingEvents' => $upcomingEvents,
            'pastEvents' => $pastEvents,
            // Layout variables
            'heading' => $heading,
            'headingpicture' => $headingpicture,
            'content' => $content,
            'area' => $area,
            'slug' => $slug,
        ]);
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
