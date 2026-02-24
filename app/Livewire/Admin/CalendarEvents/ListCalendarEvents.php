<?php

declare(strict_types=1);

namespace App\Livewire\Admin\CalendarEvents;

use App\Livewire\Traits\WithNotifications;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListCalendarEvents extends Component
{
    use WithNotifications, WithPagination;

    public string $search = '';

    public ?string $meetingFilter = null;

    public bool $uncategorizedOnly = false;

    public bool $upcomingOnly = true;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'meetingFilter', 'uncategorizedOnly', 'upcomingOnly'];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function categorize(int $eventId, ?string $meetingSlug): void
    {
        CalendarEvent::find($eventId)?->update([
            'meeting_slug' => $meetingSlug,
            'is_categorized_automatically' => false,
        ]);
        $this->success('Event categorized');
    }

    public function render(): View
    {
        $events = CalendarEvent::query()
            ->with('meeting.page')
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%"))
            ->when($this->meetingFilter, fn ($q) => $q->where('meeting_slug', $this->meetingFilter))
            ->when($this->uncategorizedOnly, fn ($q) => $q->whereNull('meeting_slug'))
            ->when($this->upcomingOnly, fn ($q) => $q->upcoming())
            ->orderBy('start_datetime', 'desc')
            ->paginate(20);

        $meetings = Meeting::with('page')->get()
            ->mapWithKeys(fn ($m) => [$m->slug => $m->page->heading ?? $m->slug]);

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
