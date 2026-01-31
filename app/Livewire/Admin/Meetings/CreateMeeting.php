<?php

namespace App\Livewire\Admin\Meetings;

use App\Models\Meeting;
use App\Models\Page;
use Livewire\Component;
use Mary\Traits\Toast;

class CreateMeeting extends Component
{
    use Toast, MeetingForm;

    public ?Meeting $meeting = null;

    public function save(): void
    {
        $validated = $this->validate();

        $meeting = Meeting::create([
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

        $this->success('Meeting created', redirectTo: route('admin.meetings.index'));
    }

    public function render()
    {
        $pages = Page::orderBy('heading')->get()->mapWithKeys(fn ($p) => [$p->id => $p->heading]);

        return view('livewire.admin.meetings.meeting-form', [
            'title' => 'Create Meeting',
            'types' => $this->getTypeOptions(),
            'frequencies' => $this->getFrequencyOptions(),
            'pages' => $pages,
        ])->layout('components.layouts.admin', ['title' => 'Create Meeting']);
    }
}
