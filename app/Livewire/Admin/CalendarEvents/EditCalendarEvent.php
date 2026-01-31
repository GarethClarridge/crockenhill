<?php

namespace App\Livewire\Admin\CalendarEvents;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Livewire\Component;
use Mary\Traits\Toast;

class EditCalendarEvent extends Component
{
    use Toast;

    public CalendarEvent $calendarEvent;

    public string $title = '';
    public ?string $description = null;
    public ?string $speaker = null;
    public ?string $location = null;
    public string $startDatetime = '';
    public string $endDatetime = '';
    public ?string $meetingSlug = null;

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'speaker' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'startDatetime' => 'required|date',
            'endDatetime' => 'required|date|after:startDatetime',
            'meetingSlug' => 'nullable|exists:meetings,slug',
        ];
    }

    public function mount(CalendarEvent $calendarEvent): void
    {
        $this->calendarEvent = $calendarEvent;
        $this->title = $calendarEvent->title;
        $this->description = $calendarEvent->description;
        $this->speaker = $calendarEvent->speaker;
        $this->location = $calendarEvent->location;
        $this->startDatetime = $calendarEvent->start_datetime->format('Y-m-d\TH:i');
        $this->endDatetime = $calendarEvent->end_datetime->format('Y-m-d\TH:i');
        $this->meetingSlug = $calendarEvent->meeting_slug;
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->calendarEvent->update([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'speaker' => $validated['speaker'],
            'location' => $validated['location'],
            'start_datetime' => $validated['startDatetime'],
            'end_datetime' => $validated['endDatetime'],
            'meeting_slug' => $validated['meetingSlug'],
            'is_categorized_automatically' => false,
        ]);

        $this->success('Calendar event updated');
    }

    public function render()
    {
        $meetings = Meeting::with('page')->get()
            ->mapWithKeys(fn ($m) => [$m->slug => $m->page->heading ?? $m->slug]);

        return view('livewire.admin.calendar-events.edit-calendar-event', [
            'meetings' => $meetings,
        ])->layout('components.layouts.admin', ['title' => 'Edit: ' . $this->calendarEvent->title]);
    }
}
