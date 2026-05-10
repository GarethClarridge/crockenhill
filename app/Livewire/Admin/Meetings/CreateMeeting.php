<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Livewire\Forms\MeetingFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithPageOptions;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

class CreateMeeting extends Component
{
    use SanitizesLogData, WithAdminAuthorization;
    use WithNotifications;
    use WithPageOptions;

    public MeetingFormData $form;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $meeting = $this->form->store();

        Log::warning('New meeting created by admin', [
            'admin_id' => auth()->id(),
            'meeting_id' => $meeting->id,
            'slug' => $this->sanitizeForLog((string) $meeting->slug),
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
        return view('livewire.admin.meetings.meeting-form', [
            'title' => 'Create Meeting',
            'types' => $this->form->typeOptions(),
            'frequencies' => $this->form->frequencyOptions(),
            'pages' => $this->pageOptions(),
        ])->layout('layouts.admin', ['title' => 'Create Meeting', 'heading' => 'Create Meeting']);
    }
}
