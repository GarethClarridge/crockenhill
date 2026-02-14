<?php

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use Livewire\Component;
use Livewire\WithPagination;

class ListPreachers extends Component
{
    use WithNotifications, WithPagination;

    public string $search = '';

    public ?bool $activeFilter = null;

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    protected $queryString = ['search', 'activeFilter'];

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Unauthorized');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(Preacher $preacher): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Unauthorized');

        $preacher->delete();

        $this->success('Preacher deleted');
    }

    public function render()
    {
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
}
