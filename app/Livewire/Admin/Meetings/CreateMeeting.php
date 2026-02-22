<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Livewire\Traits\WithNotifications;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\View\View;
use Livewire\Component;

class CreateMeeting extends Component
{
    use MeetingForm, WithNotifications;

    public ?Meeting $meeting = null;

    public function save(): void
    {
        $validated = $this->validate();

        $meeting = Meeting::create([
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

        $this->success('Meeting created', redirectTo: route('admin.meetings.index'));
    }

    public function render(): View
    {
        $pages = Page::orderBy('heading')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->heading])->toArray();

        return view('livewire.admin.meetings.meeting-form', [
            'title' => 'Create Meeting',
            'types' => $this->getTypeOptions(),
            'frequencies' => $this->getFrequencyOptions(),
            'pages' => $pages,
        ])->layout('layouts.admin', ['title' => 'Create Meeting', 'heading' => 'Create Meeting']);
    }
}
