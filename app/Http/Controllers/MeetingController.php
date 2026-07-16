<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Presenters\RelatedPagePresenter;
use App\Seo\MeetingSeoPresenter;
use App\Services\Public\PublicMeetingReadModelCache;
use App\Services\Public\PublicPageVisibilityGuard;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;

class MeetingController extends Controller
{
    public function __construct(
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
        private readonly RelatedPagePresenter $relatedPagePresenter,
        private readonly PublicPageVisibilityGuard $publicPageVisibilityGuard,
        private readonly MeetingSeoPresenter $meetingSeoPresenter,
    ) {}

    /**
     * Display the specified resource.
     */
    public function show(Meeting $meeting): ViewContract|RedirectResponse
    {
        /**
         * Performance Optimization: Limits retrieved columns for the eager-loaded page
         * to required fields for visibility checks, read models, and view rendering
         * to reduce memory usage and DB I/O.
         */
        $meeting->loadMissing('page:id,slug,heading,area,admin,navigation,description,created_at,updated_at,body,markdown');

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

        return view('meetings.show', [
            ...$readModel->toViewData(
                links: $links,
                meeting: $meeting,
                pastEvents: $pastEvents,
                page: $meeting->page,
            ),
            'eventSchema' => $this->meetingSeoPresenter->eventItemList(
                meeting: $meeting,
                events: $readModel->upcomingEvents,
                descriptionFallback: $readModel->pageDescription ?? $readModel->heading,
                image: $readModel->headingpicture,
            ),
        ]);
    }
}
