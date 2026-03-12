<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Services\CalendarService;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendarService) {}

    public function index(): View
    {
        /**
         * Performance Optimization: Limits retrieved columns for calendar events and eager-loaded
         * meetings to required fields to reduce memory usage and DB I/O.
         */
        $allEvents = CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
            ->with('meeting:id,slug,location')
            ->upcoming()
            ->confirmed()
            ->whereBetween('start_datetime', [now(), now()->addMonths(6)])
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();

        return view('calendar.index', compact('allEvents'));
    }

    public function eventsForMeeting(Meeting $meeting): View
    {
        $events = $this->calendarService->getEventsForMeeting($meeting->slug)
            ->sortBy('start_datetime');

        return view('meetings.events', compact('meeting', 'events'));
    }

    public function uncategorized(): View
    {
        $uncategorizedEvents = $this->calendarService->getUncategorizedEvents()
            ->where('start_datetime', '>=', now())
            ->take(20);

        return view('calendar.uncategorized', compact('uncategorizedEvents'));
    }
}
