<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Meetings;

use App\Enums\MeetingType;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithAdminDelete;
use App\Livewire\Traits\WithFilterableListing;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\Meeting;
use App\Traits\EscapesLikeWildcards;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListMeetings extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithAdminDelete, WithFilterableListing, WithNotifications, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'updated_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'slug',
        'day',
        'schedule',
        'start_time',
        'type',
        'location',
        'created_at',
        'updated_at',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?string $typeFilter = null;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /**
     * @return array<string, mixed>
     */
    protected function filterProperties(): array
    {
        return [
            'search' => '',
            'typeFilter' => null,
        ];
    }

    /**
     * Remove the specified meeting from storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function delete(Meeting $meeting): void
    {
        $this->adminDelete(
            model: $meeting,
            logAction: 'Meeting deleted by admin',
            logFields: [
                'meeting_id' => $meeting->id,
                'slug' => $this->sanitizeForLog((string) $meeting->slug),
            ],
        );

        $this->success('Meeting deleted');
    }

    public function render(): View
    {
        $this->sanitizeSorting();
        $this->computeHasFilters();

        $escapedSearch = $this->escapeLike(trim($this->search));

        /**
         * Performance Optimization: Limits retrieved columns for meetings and eager-loaded
         * page to required fields to reduce memory usage and DB I/O. Search terms are escaped
         * to prevent LIKE injection. Search conditions are grouped to ensure correct query logic with filters.
         */
        $query = Meeting::query()
            ->select([
                'id', 'slug', 'who', 'day', 'start_time', 'end_time',
                'type', 'location', 'page_id', 'created_at', 'updated_at',
            ])
            ->with('page:id,heading')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($sub) => $sub->whereHas('page', fn ($q2) => $q2->where('heading', 'like', "%{$escapedSearch}%"))
                ->orWhere('day', 'like', "%{$escapedSearch}%")
                ->orWhere('who', 'like', "%{$escapedSearch}%")))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter));

        if ($this->sortBy === 'schedule') {
            $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';
            $query->orderByRaw("FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') {$direction}")
                ->orderBy('start_time', $direction);
        } else {
            $query->orderBy($this->sortBy, $this->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        $meetings = $query->paginate(15);

        $headers = [
            ['key' => 'page', 'label' => 'Meeting', 'sortable' => false],
            ['key' => 'schedule', 'label' => 'Schedule', 'sortable' => true],
            ['key' => 'type', 'label' => 'Type', 'sortable' => true],
            ['key' => 'location', 'label' => 'Location', 'sortable' => true],
        ];

        return view('livewire.admin.meetings.list-meetings', [
            'meetings' => $meetings,
            'headers' => $headers,
            'types' => MeetingType::cases(),
        ])->layout('layouts.admin', ['title' => 'Meetings', 'heading' => 'Meetings']);
    }
}
