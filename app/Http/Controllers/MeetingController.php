<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Meeting;
use App\Presenters\MeetingShowPresenter;
use App\Presenters\RelatedPagePresenter;
use App\Services\PublicPageVisibilityGuard;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;

class MeetingController extends Controller
{
    public function __construct(
        private readonly MeetingShowPresenter $meetingShowPresenter,
        private readonly RelatedPagePresenter $relatedPagePresenter,
        private readonly PublicPageVisibilityGuard $publicPageVisibilityGuard,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * Performance Optimization: Limits retrieved columns for meetings to required fields
     * for the admin listing to reduce memory usage and DB I/O.
     */
    public function index(): ViewContract
    {
        $this->authorize('viewAny', Meeting::class);

        $meetings = Meeting::query()
            ->select(['id', 'slug', 'type', 'meeting_date', 'location', 'is_recurring', 'frequency'])
            ->orderBy('meeting_date', 'desc')
            ->get();

        return View::make('meetings.index', [
            'meetings' => $meetings,
            'heading' => 'Meetings',
            'description' => 'Manage church meetings.',
            'content' => '',
            'links' => collect(),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Meeting $meeting): ViewContract|RedirectResponse
    {
        $meeting->loadMissing('page');

        if ($redirect = $this->publicPageVisibilityGuard->enforce($meeting->page)) {
            return $redirect;
        }

        // Eager load page, media (for photos), and calendar events to avoid N+1 queries
        $meeting->load([
            'page',
            'media',
            'calendarEvents' => function ($query) {
                $query->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
                    ->upcoming()
                    ->confirmed()
                    ->orderBy('start_datetime')
                    ->limit(6);
            },
        ]);

        $upcomingEvents = $meeting->calendarEvents;

        // Load past events separately
        $pastEvents = \App\Models\CalendarEvent::query()
            ->select(['id', 'title', 'speaker', 'start_datetime'])
            ->where('meeting_slug', $meeting->slug)
            ->past()
            ->confirmed()
            ->orderBy('start_datetime', 'desc')
            ->limit(3)
            ->get();

        $links = $this->relatedPagePresenter->random(
            linkArea: 'community',
            slugToExclude: $meeting->slug,
            secondSlugToExclude: $meeting->slug,
            excludeAdminPages: true,
            extraExcludedSlugs: ['privacy-policy'],
        );
        $layoutData = $this->meetingShowPresenter->layoutData($meeting, $links);
        $photos = $this->meetingShowPresenter->photos($meeting);

        return view('meetings.show', [
            'meeting' => $meeting,
            'page' => $meeting->page,
            'photos' => $photos,
            'upcomingEvents' => $upcomingEvents,
            'pastEvents' => $pastEvents,
            ...$layoutData,
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
