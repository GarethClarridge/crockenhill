<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\SermonService;
use App\Livewire\Traits\WithSortableListing;
use App\Models\ChurchService;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListChurchServices extends Component
{
    use EscapesLikeWildcards, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'date';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'date',
        'service',
        'source',
        'needs_review',
        'created_at',
        'updated_at',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?string $serviceFilter = null;

    #[Url(except: null)]
    public ?bool $needsReviewFilter = null;

    public bool $hasFilters = false;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    public function mount(): void
    {
        $this->abortIfDisabled();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedServiceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedNeedsReviewFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'serviceFilter', 'needsReviewFilter']);
        $this->resetPage();
    }

    public function render(): View
    {
        $this->sanitizeSorting();

        $this->hasFilters = $this->search !== ''
            || $this->serviceFilter !== null
            || $this->needsReviewFilter !== null;

        $search = trim($this->search);
        $escapedSearch = $this->escapeLike($search);

        /**
         * Performance Optimization: Limits retrieved columns for church services to required fields
         * to reduce memory usage and DB I/O. Search terms are escaped to prevent LIKE injection.
         */
        $churchServices = ChurchService::query()
            ->select([
                'id', 'date', 'service', 'source', 'original_filename', 'needs_review', 'updated_at',
                'pending_structure_merge_source',
            ])
            ->withCount('items')
            ->when($search !== '', function (Builder $query) use ($search, $escapedSearch): void {
                $query->where(function (Builder $searchQuery) use ($search, $escapedSearch): void {
                    $searchQuery->where('original_filename', 'like', "%{$escapedSearch}%")
                        ->orWhere('source', 'like', "%{$escapedSearch}%")
                        ->orWhere('service', 'like', "%{$escapedSearch}%");

                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search) === 1) {
                        $searchQuery->orWhereDate('date', $search);
                    }
                });
            })
            ->when(
                $this->serviceFilter !== null,
                fn (Builder $query): Builder => $query->where('service', $this->serviceFilter)
            )
            ->when(
                $this->needsReviewFilter !== null,
                fn (Builder $query): Builder => $query->where('needs_review', $this->needsReviewFilter)
            )
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);

        $headers = [
            ['key' => 'date', 'label' => 'Service', 'sortable' => true],
            ['key' => 'items', 'label' => 'Items', 'sortable' => false],
            ['key' => 'source', 'label' => 'Source', 'sortable' => true],
            ['key' => 'needs_review', 'label' => 'Review', 'sortable' => true],
            ['key' => 'updated_at', 'label' => 'Uploaded', 'sortable' => true],
        ];

        return view('livewire.admin.church-services.list-church-services', [
            'churchServices' => $churchServices,
            'services' => SermonService::cases(),
            'headers' => $headers,
        ])->layout('layouts.admin', ['title' => 'Services', 'heading' => 'Services']);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
