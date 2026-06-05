<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Services\Calendar\CalendarService;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendarService) {}

    public function index(): View
    {
        $allEvents = CalendarEvent::query()
            ->forCard()
            ->with('meeting:id,slug,location')
            ->upcoming()
            ->confirmed()
            ->whereBetween('start_datetime', [now(), now()->addMonths(6)])
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();

        return view('calendar.index', [
            'allEvents' => $allEvents,
            'heading' => 'Church Calendar',
            'description' => 'Upcoming events at Crockenhill Baptist Church.',
            'content' => '',
            'area' => 'community',
            'links' => collect(),
            'slug' => 'calendar',
        ]);
    }

    public function eventsForMeeting(Meeting $meeting): View
    {
        $pastEventsLimit = 20;
        $upcomingEvents = $this->calendarService->getUpcomingEventsForMeeting($meeting->slug);
        $recentPastEvents = $this->calendarService
            ->getRecentPastEventsForMeeting($meeting->slug, $pastEventsLimit + 1);
        $pastEvents = $recentPastEvents->take($pastEventsLimit)->values();

        $meetingName = $meeting->heading ?? Str::title(str_replace('-', ' ', $meeting->slug));

        return view('meetings.events', [
            'meeting' => $meeting,
            'upcomingEvents' => $upcomingEvents,
            'pastEvents' => $pastEvents,
            'hasMorePastEvents' => $recentPastEvents->count() > $pastEventsLimit,
            'schemaEvents' => $upcomingEvents->concat($pastEvents)->values(),
            'pastEventsLimit' => $pastEventsLimit,
            'heading' => $meetingName.' - All Events',
            'description' => "View all upcoming and past calendar events for {$meetingName} at Crockenhill Baptist Church.",
            'content' => '',
            'area' => 'community',
            'links' => collect(),
            'slug' => $meeting->slug,
        ]);
    }

    public function uncategorized(): View
    {
        $uncategorizedEvents = $this->calendarService->getUncategorizedEvents()
            ->where('start_datetime', '>=', now())
            ->take(20);

        return view('calendar.uncategorized', [
            'uncategorizedEvents' => $uncategorizedEvents,
            'heading' => 'Uncategorised Events',
            'description' => 'Calendar events that still need assigning to a meeting.',
            'content' => '',
            'links' => collect(),
        ]);
    }
}
