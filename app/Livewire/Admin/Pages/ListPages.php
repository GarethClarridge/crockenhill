<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Enums\PageArea;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\Page;
use App\Traits\EscapesLikeWildcards;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListPages extends Component
{
    use EscapesLikeWildcards, WithNotifications, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'updated_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'heading',
        'area',
        'admin',
        'navigation',
        'updated_at',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?string $areaFilter = null;

    #[Url(except: null)]
    public ?bool $navigationFilter = null;

    public bool $hasFilters = false;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /** @var array<int, int|string> */
    public array $selected = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'areaFilter', 'navigationFilter']);
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

    public function render(): View
    {
        $this->sanitizeSorting();

        $this->hasFilters = ! empty($this->search)
            || $this->areaFilter !== null
            || $this->navigationFilter !== null;

        $escapedSearch = $this->escapeLike(trim($this->search));

        $pages = Page::query()
            ->select(['id', 'slug', 'heading', 'description', 'area', 'admin', 'navigation', 'updated_at'])
            ->with(['media', 'meeting'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('heading', 'like', "%{$escapedSearch}%")
                ->orWhere('description', 'like', "%{$escapedSearch}%")))
            ->when($this->areaFilter, fn ($q) => $q->where('area', $this->areaFilter))
            ->when($this->navigationFilter !== null, fn ($q) => $q->where('navigation', $this->navigationFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(15);

        $headers = [
            ['key' => 'image', 'label' => '', 'sortable' => false],
            ['key' => 'heading', 'label' => 'Heading', 'sortable' => true],
            ['key' => 'area', 'label' => 'Area', 'sortable' => true],
            ['key' => 'admin', 'label' => 'Visibility', 'sortable' => true],
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
