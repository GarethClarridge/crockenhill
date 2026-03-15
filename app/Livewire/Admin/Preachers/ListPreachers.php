<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\Preacher;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListPreachers extends Component
{
    use WithAdminAuthorization, WithNotifications, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'name';

    protected const DEFAULT_SORT_DIRECTION = 'asc';

    protected const ALLOWED_SORT_COLUMNS = [
        'name',
        'slug',
        'is_active',
        'sermons_count',
        'created_at',
        'updated_at',
    ];

    public string $search = '';

    public ?bool $activeFilter = null;

    public bool $hasFilters = false;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'activeFilter'];

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
        $this->reset(['search', 'activeFilter']);
        $this->resetPage();
    }

    public function delete(Preacher $preacher): void
    {
        $this->authorizeAdmin();

        $preacher->delete();

        $this->success('Preacher deleted');
    }

    public function render(): View
    {
        $this->sanitizeSorting();

        $this->hasFilters = ! empty($this->search)
            || $this->activeFilter !== null;

        $preachers = Preacher::query()
            ->withCount('sermons')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->activeFilter !== null, fn ($q) => $q->where('is_active', $this->activeFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);

        $headers = [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'sermons_count', 'label' => 'Sermons', 'sortable' => true],
            ['key' => 'is_active', 'label' => 'Status', 'sortable' => true],
        ];

        return view('livewire.admin.preachers.list-preachers', [
            'preachers' => $preachers,
            'headers' => $headers,
        ])->layout('layouts.admin', ['title' => 'Preachers', 'heading' => 'Preachers']);
    }
}
