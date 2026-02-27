<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Enums\MeetingType;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\Meeting;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListMeetings extends Component
{
    use WithNotifications, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'updated_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'slug',
        'day',
        'start_time',
        'type',
        'is_recurring',
        'location',
        'created_at',
        'updated_at',
    ];

    public string $search = '';

    public ?string $typeFilter = null;

    public ?bool $recurringFilter = null;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'typeFilter', 'recurringFilter'];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(Meeting $meeting): void
    {
        $meeting->delete();
        $this->success('Meeting deleted');
    }

    public function render(): View
    {
        $this->sanitizeSorting();

        $meetings = Meeting::query()
            ->with(['page', 'calendarEvents'])
            ->when($this->search, fn ($q) => $q->whereHas('page', fn ($q2) => $q2->where('heading', 'like', "%{$this->search}%"))
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
        ])->layout('layouts.admin', ['title' => 'Meetings', 'heading' => 'Meetings']);
    }
}
