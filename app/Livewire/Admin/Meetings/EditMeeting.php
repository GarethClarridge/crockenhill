<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Livewire\Forms\MeetingFormData;
use App\Livewire\Traits\WithAdminSave;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithPageOptions;
use App\Models\Meeting;
use Illuminate\View\View;
use Livewire\Component;

class EditMeeting extends Component
{
    use WithAdminSave;
    use WithNotifications;
    use WithPageOptions;

    public Meeting $meeting;

    public MeetingFormData $form;

    public function mount(Meeting $meeting): void
    {
        $this->meeting = $meeting;
        $this->form->setMeeting($meeting);
    }

    public function save(): void
    {
        $this->adminSave(
            save: function (): array {
                $this->form->update();

                $fresh = $this->meeting->fresh();

                return [
                    'meeting_id' => $this->meeting->id,
                    'slug' => $this->sanitizeForLog($fresh instanceof Meeting ? (string) $fresh->slug : (string) $this->meeting->slug),
                ];
            },
            logAction: 'Meeting updated by admin',
        );

        $this->success('Meeting updated');
    }

    /**
     * Render the component.
     *
     * Performance Optimization: Limits retrieved columns for pages to required fields
     * (id and heading) for the dropdown selection to reduce memory usage.
     */
    public function render(): View
    {
        $pageTitle = $this->meeting->page->heading ?? $this->meeting->slug;

        return view('livewire.admin.meetings.meeting-form', [
            'title' => 'Edit Meeting',
            'types' => $this->form->typeOptions(),
            'pages' => $this->pageOptions(),
        ])->layout('layouts.admin', ['title' => 'Edit: '.$pageTitle, 'heading' => 'Edit Meeting']);
    }
}
