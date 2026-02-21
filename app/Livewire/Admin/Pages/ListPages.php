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

    private const DEFAULT_SORT_COLUMN = 'updated_at';

    private const DEFAULT_SORT_DIRECTION = 'desc';

    private const ALLOWED_SORT_COLUMNS = [
        'heading',
        'area',
        'navigation',
        'updated_at',
    ];

    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    public string $search = '';

    public ?string $areaFilter = null;

    public ?bool $navigationFilter = null;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    public array $selected = [];

    protected $queryString = ['search', 'areaFilter', 'navigationFilter'];

    public function sort(string $column): void
    {
        if (! in_array($column, self::ALLOWED_SORT_COLUMNS, true)) {
            $this->sortBy = self::DEFAULT_SORT_COLUMN;
            $this->sortDirection = self::DEFAULT_SORT_DIRECTION;

            return;
        }

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
        $this->sanitizeSorting();

        $pages = Page::query()
            ->select(['id', 'slug', 'heading', 'description', 'area', 'navigation', 'updated_at'])
            ->with(['media', 'meeting'])
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
