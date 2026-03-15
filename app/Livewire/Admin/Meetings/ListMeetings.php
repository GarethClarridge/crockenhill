<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Enums\MeetingType;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\Meeting;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListMeetings extends Component
{
    use WithAdminAuthorization, WithNotifications, WithPagination, WithSortableListing;

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

    public bool $hasFilters = false;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'typeFilter', 'recurringFilter'];

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'typeFilter', 'recurringFilter']);
        $this->resetPage();
    }

    public function delete(Meeting $meeting): void
    {
        $this->authorizeAdmin();

        $meeting->delete();
        $this->success('Meeting deleted');
    }

    public function render(): View
    {
        $this->sanitizeSorting();

        $this->hasFilters = ! empty($this->search)
            || $this->typeFilter !== null
            || $this->recurringFilter !== null;

        /**
         * Performance Optimization: Limits retrieved columns for meetings and eager-loaded
         * page to required fields to reduce memory usage and DB I/O. Unused
         * calendarEvents relationship is removed from eager loading.
         * Search conditions are grouped to ensure correct query logic with filters.
         */
        $meetings = Meeting::query()
            ->select([
                'id', 'slug', 'who', 'day', 'start_time', 'end_time',
                'type', 'is_recurring', 'frequency', 'location', 'page_id', 'created_at', 'updated_at',
            ])
            ->with('page:id,heading')
            ->when($this->search, fn ($q) => $q->where(fn ($sub) => $sub->whereHas('page', fn ($q2) => $q2->where('heading', 'like', "%{$this->search}%"))
                ->orWhere('day', 'like', "%{$this->search}%")
                ->orWhere('who', 'like', "%{$this->search}%")))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->recurringFilter !== null, fn ($q) => $q->where('is_recurring', $this->recurringFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(15);

        $headers = [
            ['key' => 'page', 'label' => 'Meeting', 'sortable' => false],
            ['key' => 'day', 'label' => 'Schedule', 'sortable' => true],
            ['key' => 'type', 'label' => 'Type', 'sortable' => true],
            ['key' => 'is_recurring', 'label' => 'Recurring', 'sortable' => true],
            ['key' => 'location', 'label' => 'Location', 'sortable' => true],
        ];

        return view('livewire.admin.meetings.list-meetings', [
            'meetings' => $meetings,
            'headers' => $headers,
            'types' => MeetingType::cases(),
        ])->layout('layouts.admin', ['title' => 'Meetings', 'heading' => 'Meetings']);
    }
}
