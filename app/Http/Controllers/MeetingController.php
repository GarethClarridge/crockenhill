<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Presenters\RelatedPagePresenter;
use App\Services\PublicMeetingReadModelCache;
use App\Services\PublicPageVisibilityGuard;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;

class MeetingController extends Controller
{
    public function __construct(
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
        private readonly RelatedPagePresenter $relatedPagePresenter,
        private readonly PublicPageVisibilityGuard $publicPageVisibilityGuard,
    ) {}

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
}
