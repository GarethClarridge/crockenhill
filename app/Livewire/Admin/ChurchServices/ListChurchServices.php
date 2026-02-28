<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\SermonService;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithSortableListing;
use App\Models\ChurchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListChurchServices extends Component
{
    use WithAdminAuthorization, WithPagination, WithSortableListing;

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

    public string $search = '';

    public ?string $serviceFilter = null;

    public ?string $needsReviewFilter = null;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'serviceFilter', 'needsReviewFilter'];

    public function mount(): void
    {
        $this->authorizeAdmin();
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

    public function render(): View
    {
        $this->sanitizeSorting();

        $search = trim($this->search);

        $churchServices = ChurchService::query()
            ->withCount('items')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('original_filename', 'like', "%{$search}%")
                        ->orWhere('source', 'like', "%{$search}%")
                        ->orWhere('service', 'like', "%{$search}%");

                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search) === 1) {
                        $searchQuery->orWhereDate('date', $search);
                    }
                });
            })
            ->when(
                $this->serviceFilter !== null && $this->serviceFilter !== '',
                fn (Builder $query): Builder => $query->where('service', $this->serviceFilter)
            )
            ->when(
                $this->needsReviewFilter !== null && $this->needsReviewFilter !== '',
                fn (Builder $query): Builder => $query->where('needs_review', $this->needsReviewFilter === '1')
            )
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);

        $headers = [
            ['key' => 'date', 'label' => 'Service'],
            ['key' => 'items', 'label' => 'Items'],
            ['key' => 'source', 'label' => 'Source'],
            ['key' => 'needs_review', 'label' => 'Review'],
            ['key' => 'updated_at', 'label' => 'Uploaded'],
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
