<?php

namespace App\Livewire\Admin\Meetings;

use App\Models\Meeting;
use App\Models\Page;
use Livewire\Component;
use Mary\Traits\Toast;

class EditMeeting extends Component
{
    use Toast, MeetingForm;

    public Meeting $meeting;

    public function mount(Meeting $meeting): void
    {
        $this->meeting = $meeting;
        $this->slug = $meeting->slug;
        $this->type = $meeting->type->value;
        $this->startTime = $meeting->StartTime ? $meeting->StartTime->format('H:i') : '';
        $this->endTime = $meeting->EndTime ? $meeting->EndTime->format('H:i') : '';
        $this->day = $meeting->day;
        $this->location = $meeting->location ?? '';
        $this->who = $meeting->who;
        $this->pictures = $meeting->pictures;
        $this->leadersPhone = $meeting->LeadersPhone ?? '';
        $this->leadersEmail = $meeting->LeadersEmail ?? '';
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
            'StartTime' => $validated['startTime'] ? date('Y-m-d') . ' ' . $validated['startTime'] : null,
            'EndTime' => $validated['endTime'] ? date('Y-m-d') . ' ' . $validated['endTime'] : null,
            'day' => $validated['day'],
            'location' => $validated['location'],
            'who' => $validated['who'],
            'pictures' => $validated['pictures'],
            'LeadersPhone' => $validated['leadersPhone'],
            'LeadersEmail' => $validated['leadersEmail'],
            'meeting_date' => $validated['meetingDate'],
            'is_recurring' => $validated['isRecurring'],
            'frequency' => $validated['frequency'],
            'page_id' => $validated['pageId'],
        ]);

        $this->success('Meeting updated');
    }

    public function render()
    {
        $pages = Page::orderBy('heading')->get()->mapWithKeys(fn ($p) => [$p->id => $p->heading]);

        $pageTitle = $this->meeting->page->heading ?? $this->meeting->slug;

        return view('livewire.admin.meetings.meeting-form', [
            'title' => 'Edit Meeting',
            'types' => $this->getTypeOptions(),
            'frequencies' => $this->getFrequencyOptions(),
            'pages' => $pages,
        ])->layout('components.layouts.admin', ['title' => 'Edit: ' . $pageTitle]);
    }
}
