<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Preachers;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithFilterableListing;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\Preacher;
use App\Traits\EscapesLikeWildcards;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListPreachers extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithFilterableListing, WithNotifications, WithPagination, WithSortableListing;

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

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?bool $activeFilter = null;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterProperties(): array
    {
        return [
            'search' => '',
            'activeFilter' => null,
        ];
    }

    public function delete(Preacher $preacher): void
    {
        // Defense-in-depth: enforce admin authorization internally
        $this->authorizeAdmin();

        $preacher->delete();

        $this->success('Preacher deleted');
    }

    public function render(): View
    {
        $this->sanitizeSorting();
        $this->computeHasFilters();

        $escapedSearch = $this->escapeLike(trim($this->search));

        /**
         * Performance Optimization: Limits retrieved columns for preachers to required fields
         * to reduce memory usage and DB I/O. Search terms are escaped to prevent LIKE injection.
         */
        $preachers = Preacher::query()
            ->select(['id', 'name', 'slug', 'is_active'])
            ->withCount('sermons')
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$escapedSearch}%"))
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
