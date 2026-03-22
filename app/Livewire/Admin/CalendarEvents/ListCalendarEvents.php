<?php

declare(strict_types=1);

namespace App\Livewire\Admin\CalendarEvents;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Traits\EscapesLikeWildcards;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListCalendarEvents extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithNotifications, WithPagination;

    public string $search = '';

    public ?string $meetingFilter = null;

    public bool $uncategorizedOnly = false;

    public bool $upcomingOnly = true;

    public bool $hasFilters = false;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'meetingFilter', 'uncategorizedOnly', 'upcomingOnly'];

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'meetingFilter', 'uncategorizedOnly', 'upcomingOnly']);
        $this->resetPage();
    }

    public function categorize(int $eventId, ?string $meetingSlug): void
    {
        $this->authorizeAdmin();

        CalendarEvent::find($eventId)?->update([
            'meeting_slug' => $meetingSlug,
            'is_categorized_automatically' => false,
        ]);
        $this->success('Event categorized');
    }

    public function render(): View
    {
        $this->hasFilters = ! empty($this->search)
            || $this->meetingFilter !== null
            || $this->uncategorizedOnly === true
            || $this->upcomingOnly === false;

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

        $meetings = Meeting::getForAdminList();

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
