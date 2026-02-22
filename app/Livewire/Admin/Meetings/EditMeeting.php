<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Livewire\Traits\WithNotifications;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\View\View;
use Livewire\Component;

class EditMeeting extends Component
{
    use MeetingForm, WithNotifications;

    public Meeting $meeting;

    public function mount(Meeting $meeting): void
    {
        $this->meeting = $meeting;
        $this->slug = $meeting->slug;
        $this->type = $meeting->type->value;
        $this->startTime = $meeting->start_time ? $meeting->start_time->format('H:i') : '';
        $this->endTime = $meeting->end_time ? $meeting->end_time->format('H:i') : '';
        $this->day = $meeting->day;
        $this->location = $meeting->location ?? '';
        $this->who = $meeting->who;
        $this->pictures = $meeting->pictures;
        $this->leadersPhone = $meeting->leaders_phone ?? '';
        $this->leadersEmail = $meeting->leaders_email ?? '';
        $this->meetingDate = $meeting->meeting_date ? $meeting->meeting_date->format('Y-m-d') : '';
        $this->isRecurring = $meeting->is_recurring;
        $this->frequency = $meeting->frequency?->value;
        $this->pageId = $meeting->page_id;
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->meeting->update([
            'slug' => $validated['slug'],
            'type' => $validated['type'],
            'start_time' => $validated['startTime'] ? date('Y-m-d').' '.$validated['startTime'] : null,
            'end_time' => $validated['endTime'] ? date('Y-m-d').' '.$validated['endTime'] : null,
            'day' => $validated['day'],
            'location' => $validated['location'],
            'who' => $validated['who'],
            'pictures' => $validated['pictures'],
            'leaders_phone' => $validated['leadersPhone'],
            'leaders_email' => $validated['leadersEmail'],
            'meeting_date' => $validated['meetingDate'],
            'is_recurring' => $validated['isRecurring'],
            'frequency' => $validated['frequency'],
            'page_id' => $validated['pageId'],
        ]);

        $this->success('Meeting updated');
    }

    public function render(): View
    {
        $pages = Page::orderBy('heading')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->heading])->toArray();

        $pageTitle = $this->meeting->page->heading ?? $this->meeting->slug;

        return view('livewire.admin.meetings.meeting-form', [
            'title' => 'Edit Meeting',
            'types' => $this->getTypeOptions(),
            'frequencies' => $this->getFrequencyOptions(),
            'pages' => $pages,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$pageTitle, 'heading' => 'Edit Meeting']);
    }
}
