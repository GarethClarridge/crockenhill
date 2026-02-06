<?php

namespace App\Livewire\Admin\Pages;

use App\Enums\PageArea;
use App\Livewire\Traits\WithNotifications;
use App\Models\Page;
use Livewire\Component;
use Livewire\WithPagination;

class ListPages extends Component
{
    use WithNotifications, WithPagination;

    public string $search = '';

    public ?string $areaFilter = null;

    public ?bool $navigationFilter = null;

    public string $sortBy = 'updated_at';

    public string $sortDirection = 'desc';

    public array $selected = [];

    protected $queryString = ['search', 'areaFilter', 'navigationFilter'];

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

    public function delete(Page $page): void
    {
        $page->delete();
        $this->success('Page deleted');
    }

    public function deleteSelected(): void
    {
        Page::whereIn('id', $this->selected)->delete();
        $this->selected = [];
        $this->success('Pages deleted');
    }

    public function render()
    {
        $pages = Page::query()
            ->when($this->search, fn ($q) => $q->where('heading', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%"))
            ->when($this->areaFilter, fn ($q) => $q->where('area', $this->areaFilter))
            ->when($this->navigationFilter !== null, fn ($q) => $q->where('navigation', $this->navigationFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(15);

        $headers = [
            ['key' => 'image', 'label' => '', 'sortable' => false],
            ['key' => 'heading', 'label' => 'Heading', 'sortable' => true],
            ['key' => 'area', 'label' => 'Area', 'sortable' => true],
            ['key' => 'navigation', 'label' => 'Nav', 'sortable' => true],
            ['key' => 'meeting', 'label' => 'Meeting', 'sortable' => false],
            ['key' => 'updated_at', 'label' => 'Updated', 'sortable' => true],
        ];

        return view('livewire.admin.pages.list-pages', [
            'pages' => $pages,
            'headers' => $headers,
            'areas' => PageArea::cases(),
        ])->layout('layouts.admin', ['title' => 'Pages', 'heading' => 'Pages']);
    }
}
