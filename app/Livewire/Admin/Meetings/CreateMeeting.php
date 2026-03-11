<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\View\View;
use Livewire\Component;

class CreateMeeting extends Component
{
    use MeetingForm, WithAdminAuthorization, WithNotifications;

    public ?Meeting $meeting = null;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function save(): void
    {
        $this->authorizeAdmin();

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

    /**
     * Render the component.
     *
     * Performance Optimization: Limits retrieved columns for pages to required fields
     * (id and heading) for the dropdown selection to reduce memory usage.
     */
    public function render(): View
    {
        $pages = Page::query()
            ->select(['id', 'heading'])
            ->orderBy('heading')
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->heading])
            ->toArray();

        return view('livewire.admin.meetings.meeting-form', [
            'title' => 'Create Meeting',
            'types' => $this->getTypeOptions(),
            'frequencies' => $this->getFrequencyOptions(),
            'pages' => $pages,
        ])->layout('layouts.admin', ['title' => 'Create Meeting', 'heading' => 'Create Meeting']);
    }
}
