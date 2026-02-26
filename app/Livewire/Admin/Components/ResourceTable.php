<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Components;

use App\Livewire\Traits\WithNotifications;
use Livewire\Component;
use Livewire\WithPagination;

abstract class ResourceTable extends Component
{
    use WithNotifications, WithPagination;

    public string $search = '';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    /** @var array<int, int|string> */
    public array $selected = [];

    /** @var array<int, string> */
    protected array $queryString = ['search', 'sortBy', 'sortDirection'];

    public function mount(): void
    {
        // Ensure only admins can access resource tables
        $this->authorizeAdmin();
    }

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

    abstract protected function getQuery(): mixed;

    /**
     * @return array<int, array<string, mixed>>
     */
    abstract protected function getHeaders(): array;

    public function deleteSelected(): void
    {
        // Defense in depth: verify admin status before bulk operations
        $this->authorizeAdmin();

        if (empty($this->selected)) {
            $this->error('No items selected');

            return;
        }

        $count = count($this->selected);
        $this->getModelClass()::whereIn('id', $this->selected)->delete();
        $this->selected = [];
        $this->success("{$count} item(s) deleted");
    }

    abstract protected function getModelClass(): string;

    protected function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin === true, 403, 'Unauthorized');
    }
}
