<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategorizeEventRequest;
use App\Models\Meeting;
use App\Services\CalendarService;
use App\Services\GoogleCalendarSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CalendarAdminController extends Controller
{
    public function __construct(
        private CalendarService $calendarService,
        private GoogleCalendarSyncService $syncService,
    ) {}

    public function uncategorizedEvents(): View
    {
        $uncategorizedEvents = $this->calendarService->getUncategorizedEvents();
        $meetings = Meeting::orderBy('slug')->get();

        return view('admin.calendar.uncategorized', compact('uncategorizedEvents', 'meetings'));
    }

    public function categorizeEvent(CategorizeEventRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $event = $this->calendarService->manuallyCategorizeEvent(
            $validated['event_id'],
            $validated['meeting_slug']
        );

        return redirect()->back()->with('success', "Event '{$event->title}' categorized successfully");
    }

    public function patternManagement(): View
    {
        $patterns = config('calendar.meeting_patterns');
        $meetings = Meeting::orderBy('slug')->get();

        return view('admin.calendar.patterns', compact('patterns', 'meetings'));
    }

    public function syncCalendar(): RedirectResponse
    {
        try {
            $report = $this->syncService->syncFromGoogleCalendar();

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
