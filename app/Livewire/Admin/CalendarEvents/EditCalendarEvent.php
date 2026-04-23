<?php

declare(strict_types=1);

namespace App\Livewire\Admin\CalendarEvents;

use App\Enums\CalendarEventStatus;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class EditCalendarEvent extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public CalendarEvent $calendarEvent;

    public string $title = '';

    public ?string $description = null;

    public ?string $speaker = null;

    public ?string $location = null;

    public string $startDatetime = '';

    public string $endDatetime = '';

    public ?string $meetingSlug = null;

    public string $status = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'speaker' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'startDatetime' => 'required|date',
            'endDatetime' => 'required|date|after_or_equal:startDatetime',
            'meetingSlug' => 'nullable|exists:meetings,slug',
            'status' => ['required', Rule::enum(CalendarEventStatus::class)],
        ];
    }

    public function mount(CalendarEvent $calendarEvent): void
    {

        $this->authorizeAdmin();

        $this->calendarEvent = $calendarEvent;
        $this->title = $calendarEvent->title;
        $this->description = $calendarEvent->description;
        $this->speaker = $calendarEvent->speaker;
        $this->location = $calendarEvent->location;
        $this->startDatetime = $calendarEvent->start_datetime->format('Y-m-d\TH:i');
        $this->endDatetime = $calendarEvent->end_datetime->format('Y-m-d\TH:i');
        $this->meetingSlug = $calendarEvent->meeting_slug;
        $this->status = $calendarEvent->status->value;
    }

    public function save(): void
    {

        $this->authorizeAdmin();

        $validated = $this->validate();

        $oldMeetingSlug = $this->calendarEvent->meeting_slug;

        $this->calendarEvent->update([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'speaker' => $validated['speaker'],
            'location' => $validated['location'],
            'start_datetime' => $validated['startDatetime'],
            'end_datetime' => $validated['endDatetime'],
            'status' => $validated['status'],
        ]);

        if ($oldMeetingSlug !== $validated['meetingSlug']) {
            app(\App\Actions\CategorizeCalendarEvent::class)->execute($this->calendarEvent, $validated['meetingSlug']);
        }

        $this->success('Calendar event updated');
    }

    public function render(): View
    {
        /**
         * Performance Optimization: Uses cached meeting list for admin dropdowns
         * to reduce redundant DB queries.
         */
        $meetings = Meeting::getForAdminList();

        return view('livewire.admin.calendar-events.edit-calendar-event', [
            'meetings' => $meetings,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->calendarEvent->title, 'heading' => 'Edit Calendar Event']);
    }
}
