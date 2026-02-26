<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListPreachers extends Component
{
    use WithNotifications, WithPagination;

    private const DEFAULT_SORT_COLUMN = 'name';

    private const DEFAULT_SORT_DIRECTION = 'asc';

    private const ALLOWED_SORT_COLUMNS = [
        'name',
        'slug',
        'is_active',
        'sermons_count',
        'created_at',
        'updated_at',
    ];

    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    public string $search = '';

    public ?bool $activeFilter = null;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'activeFilter'];

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin === true, 403, 'Unauthorized');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(Preacher $preacher): void
    {
        abort_unless(auth()->user()?->is_admin === true, 403, 'Unauthorized');

        $preacher->delete();

        $this->success('Preacher deleted');
    }

    public function render(): View
    {
        $this->sanitizeSorting();

        $preachers = Preacher::query()
            ->withCount('sermons')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->activeFilter !== null, fn ($q) => $q->where('is_active', $this->activeFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);

        return view('livewire.admin.preachers.list-preachers', [
            'preachers' => $preachers,
        ])->layout('layouts.admin', ['title' => 'Preachers', 'heading' => 'Preachers']);
    }

    private function sanitizeSorting(): void
    {
        if (! in_array($this->sortBy, self::ALLOWED_SORT_COLUMNS, true)) {
            $this->sortBy = self::DEFAULT_SORT_COLUMN;
        }

        if (! in_array($this->sortDirection, self::ALLOWED_SORT_DIRECTIONS, true)) {
            $this->sortDirection = self::DEFAULT_SORT_DIRECTION;
        }
    }
}
