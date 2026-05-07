<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Meeting;
use App\Presenters\RelatedPagePresenter;
use App\Services\PublicMeetingReadModelCache;
use App\Services\PublicPageVisibilityGuard;
use App\Traits\SanitizesLogData;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;

class MeetingController extends Controller
{
    use SanitizesLogData;

    public function __construct(
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
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

        $links = $this->relatedPagePresenter->random(
            linkArea: 'community',
            slugToExclude: $meeting->slug,
            secondSlugToExclude: $meeting->slug,
            excludeAdminPages: true,
            extraExcludedSlugs: ['privacy-policy'],
        );
        $readModel = $this->publicMeetingReadModelCache->get($meeting);
        $pastEvents = $this->publicMeetingReadModelCache->getPastEventsForMeeting($meeting);

        return view('meetings.show', $readModel->toViewData(
            links: $links,
            meeting: $meeting,
            pastEvents: $pastEvents,
            page: $meeting->page,
        ));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMeetingRequest $request, Meeting $meeting): RedirectResponse
    {
        $validated = $request->validated();

        $meeting->update($validated);

        $fresh = $meeting->fresh();
        Log::warning('Meeting updated by admin', [
            'admin_id' => auth()->id(),
            'meeting_id' => $meeting->id,
            'slug' => $this->sanitizeForLog($fresh instanceof Meeting ? (string) $fresh->slug : (string) $meeting->slug),
        ]);

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

        Log::warning('Meeting deleted by admin', [
            'admin_id' => auth()->id(),
            'meeting_id' => $meeting->id,
            'slug' => $this->sanitizeForLog((string) $meeting->slug),
        ]);

        $meetingSlug = $meeting->slug;
        $meeting->delete();

        Session::flash('message', 'Meeting "'.$meetingSlug.'" successfully deleted!');

        return Redirect::to('/church/members/meetings');
    }
}
