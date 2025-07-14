<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Services\CalendarService;
use Illuminate\Http\Request;

class CalendarAdminController extends Controller
{
    public function uncategorizedEvents()
    {
        $calendarService = app(CalendarService::class);
        $uncategorizedEvents = $calendarService->getUncategorizedEvents();
        $meetings = Meeting::orderBy('slug')->get();

        return view('admin.calendar.uncategorized', compact('uncategorizedEvents', 'meetings'));
    }

    public function categorizeEvent(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:calendar_events,id',
            'meeting_slug' => 'required|string|exists:meetings,slug',
        ]);

        $calendarService = app(CalendarService::class);
        $event = $calendarService->manuallyCategorizeEvent(
            $validated['event_id'],
            $validated['meeting_slug']
        );

        return redirect()->back()->with('success', "Event '{$event->title}' categorized successfully");
    }

    public function patternManagement()
    {
        $patterns = config('calendar.meeting_patterns');
        $meetings = Meeting::orderBy('slug')->get();

        return view('admin.calendar.patterns', compact('patterns', 'meetings'));
    }

    public function syncCalendar()
    {
        try {
            $calendarService = app(CalendarService::class);
            $report = $calendarService->syncFromGoogleCalendar();

            return redirect()->back()->with('success',
                "Sync completed! Processed: {$report['processed_events']}, ".
                "Deleted: {$report['deleted_events']}, ".
                "Uncategorized: {$report['uncategorized_events']}"
            );
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Sync failed: '.$e->getMessage());
        }
    }
}
