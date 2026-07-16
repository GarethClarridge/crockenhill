<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategorizeEventRequest;
use App\Models\Meeting;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\GoogleCalendarSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CalendarAdminController extends Controller
{
    public function __construct(
        private CalendarService $calendarService,
        private GoogleCalendarSyncService $syncService,
    ) {}

    /**
     * Display a listing of uncategorized calendar events.
     *
     * Performance Optimization: Limits retrieved columns for meetings to required fields
     * for the categorization dropdown to reduce memory usage.
     */
    public function uncategorizedEvents(): View
    {
        $uncategorizedEvents = $this->calendarService->getUncategorizedEvents();
        $meetings = Meeting::query()
            ->select(['id', 'slug'])
            ->orderBy('slug')
            ->get();

        return view('admin.calendar.uncategorized', [
            'uncategorizedEvents' => $uncategorizedEvents,
            'meetings' => $meetings,
            'heading' => 'Categorise calendar events',
            'description' => 'Review uncategorised calendar events',
            'content' => '',
            'links' => collect(),
        ]);
    }

    public function categorizeEvent(CategorizeEventRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $result = $this->calendarService->manuallyCategorizeEvent(
            $validated['event_id'],
            $validated['meeting_slug']
        );

        $message = $result->googleSynced
            ? "Event '{$result->event->title}' categorised and synced to Google Calendar"
            : "Event '{$result->event->title}' categorised (Google sync failed — will retry on next sync)";

        return redirect()->back()->with('success', $message);
    }

    /**
     * Display the calendar categorization patterns.
     *
     * Performance Optimization: Limits retrieved columns for meetings to required fields
     * for linking to meeting pages.
     */
    public function patternManagement(): View
    {
        $patterns = config('calendar.meeting_patterns');
        $meetings = Meeting::query()
            ->select(['id', 'slug'])
            ->orderBy('slug')
            ->get();

        return view('admin.calendar.patterns', [
            'patterns' => $patterns,
            'meetings' => $meetings,
            'heading' => 'Calendar patterns',
            'description' => 'Calendar pattern mappings for meetings',
            'content' => '',
            'links' => collect(),
        ]);
    }

    public function syncCalendar(): RedirectResponse
    {
        try {
            $report = $this->syncService->syncFromGoogleCalendar();

            return redirect()->back()->with('success',
                "Sync completed! Processed: {$report['processed_events']}, ".
                "Deleted: {$report['deleted_events']}, ".
                "Uncategorised: {$report['uncategorized_events']}"
            );
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Sync failed due to an internal error.');
        }
    }
}
