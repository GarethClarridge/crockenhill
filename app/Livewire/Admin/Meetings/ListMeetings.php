<?php

namespace App\Livewire\Admin\Meetings;

use App\Enums\MeetingType;
use App\Models\Meeting;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class ListMeetings extends Component
{
    use WithPagination, Toast;

    public string $search = '';
    public ?string $typeFilter = null;
    public ?bool $recurringFilter = null;
    public string $sortBy = 'updated_at';
    public string $sortDirection = 'desc';

    protected $queryString = ['search', 'typeFilter', 'recurringFilter'];

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(Meeting $meeting): void
    {
        $meeting->delete();
        $this->success('Meeting deleted');
    }

    public function render()
    {
        $meetings = Meeting::query()
            ->with(['page', 'calendarEvents'])
            ->when($this->search, fn ($q) => $q->whereHas('page', fn ($q2) =>
                $q2->where('heading', 'like', "%{$this->search}%"))
                ->orWhere('day', 'like', "%{$this->search}%")
                ->orWhere('who', 'like', "%{$this->search}%"))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->recurringFilter !== null, fn ($q) => $q->where('is_recurring', $this->recurringFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(15);

        $headers = [
            ['key' => 'page', 'label' => 'Meeting'],
            ['key' => 'schedule', 'label' => 'Schedule'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'recurring', 'label' => 'Recurring'],
            ['key' => 'location', 'label' => 'Location'],
        ];

        return view('livewire.admin.meetings.list-meetings', [
            'meetings' => $meetings,
            'headers' => $headers,
            'types' => MeetingType::cases(),
        ])->layout('components.layouts.admin', ['title' => 'Meetings']);
    }
}
