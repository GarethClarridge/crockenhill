<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Services\CalendarService;

class CalendarController extends Controller
{
    public function index()
    {
        $allEvents = CalendarEvent::with('meeting')
            ->upcoming()
            ->confirmed()
            ->whereBetween('start_datetime', [now(), now()->addMonths(6)])
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();

        return view('calendar.index', compact('allEvents'));
    }

    public function meetingsIndex()
    {
        $meetings = Meeting::with([
            'calendarEvents' => function ($query) {
                $query->upcoming()
                    ->confirmed()
                    ->orderBy('start_datetime')
                    ->limit(config('calendar.performance.eager_load_limit', 5));
            },
        ])->get();

        return view('meetings.index', compact('meetings'));
    }

    public function eventsForMeeting(Meeting $meeting)
    {
        $calendarService = app(CalendarService::class);
        $events = $calendarService->getEventsForMeeting($meeting->slug)
            ->sortBy('start_datetime');

        return view('meetings.events', compact('meeting', 'events'));
    }

    public function uncategorized()
    {
        $calendarService = app(CalendarService::class);
        $uncategorizedEvents = $calendarService->getUncategorizedEvents()
            ->where('start_datetime', '>=', now())
            ->take(20);

        return view('calendar.uncategorized', compact('uncategorizedEvents'));
    }
}
