<?php

declare(strict_types=1);

namespace App\Livewire\Admin\CalendarEvents;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithFilterableListing;
use App\Livewire\Traits\WithNotifications;
use App\Models\CalendarEvent;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\GoogleCalendarSyncService;
use App\Services\Public\MeetingListCache;
use App\Traits\EscapesLikeWildcards;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListCalendarEvents extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithFilterableListing, WithNotifications, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?string $meetingFilter = null;

    #[Url(except: false)]
    public bool $uncategorizedOnly = false;

    #[Url(except: true)]
    public bool $upcomingOnly = true;

    /**
     * @return array<string, mixed>
     */
    protected function filterProperties(): array
    {
        return [
            'search' => '',
            'meetingFilter' => null,
            'uncategorizedOnly' => false,
            'upcomingOnly' => true,
        ];
    }

    public function categorize(int $eventId, ?string $meetingSlug): void
    {
        $this->authorizeAdmin();

        $event = CalendarEvent::query()->find($eventId);

        if ($event) {
            $calendarService = app(CalendarService::class);

            if ($meetingSlug === null) {
                $calendarService->manuallyUnCategorizeEvent($event->id);
            } else {
                $calendarService->manuallyCategorizeEvent($event->id, $meetingSlug);
            }
        }

        $this->success('Event categorised');
    }

    public function syncCalendar(GoogleCalendarSyncService $syncService): void
    {
        $this->authorizeAdmin();

        try {
            $report = $syncService->syncFromGoogleCalendar();

            $this->success(
                "Sync completed! Processed: {$report['processed_events']}, ".
                "Deleted: {$report['deleted_events']}, ".
                "Uncategorised: {$report['uncategorized_events']}"
            );
        } catch (\Exception) {
            $this->error('Sync failed due to an internal error.');
        }
    }

    public function render(): View
    {
        $this->computeHasFilters();

        /**
         * Performance Optimization: Limits retrieved columns for calendar events and eager-loaded
         * meeting/page to required fields to reduce memory usage and DB I/O.
         */
        $escapedSearch = $this->escapeLike(trim($this->search));

        $events = CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'speaker', 'location', 'start_datetime', 'end_datetime', 'status', 'is_categorized_automatically'])
            ->with(['meeting:id,slug,page_id', 'meeting.page:id,heading'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('title', 'like', "%{$escapedSearch}%")
                ->orWhere('description', 'like', "%{$escapedSearch}%")))
            ->when($this->meetingFilter, fn ($q) => $q->where('meeting_slug', $this->meetingFilter))
            ->when($this->uncategorizedOnly, fn ($q) => $q->whereNull('meeting_slug'))
            ->when($this->upcomingOnly, fn ($q) => $q->upcoming())
            ->orderBy('start_datetime', 'desc')
            ->paginate(20);

        $meetings = app(MeetingListCache::class)->forAdminList();

        $headers = [
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'datetime', 'label' => 'Date & Time'],
            ['key' => 'meeting', 'label' => 'Meeting'],
            ['key' => 'location', 'label' => 'Location'],
            ['key' => 'status', 'label' => 'Status'],
        ];

        return view('livewire.admin.calendar-events.list-calendar-events', [
            'events' => $events,
            'meetings' => $meetings,
            'headers' => $headers,
        ])->layout('layouts.admin', ['title' => 'Calendar Events', 'heading' => 'Calendar Events']);
    }
}
