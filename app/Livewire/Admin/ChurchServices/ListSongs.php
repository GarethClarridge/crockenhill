<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\SermonService;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithSortableListing;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListSongs extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'usage_count';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'title',
        'usage_count',
        'services_count',
        'last_used_date',
        'ccli_number',
    ];

    #[Url(as: 'search', except: '')]
    public string $search = '';

    #[Url(as: 'serviceFilter', except: null)]
    public ?string $serviceFilter = null;

    #[Url(as: 'dateFrom', except: null)]
    public ?string $dateFrom = null;

    #[Url(as: 'dateTo', except: null)]
    public ?string $dateTo = null;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

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

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $this->sanitizeSorting();

        $search = trim($this->search);
        $escapedSearch = $this->escapeLike($search);

        $usageSubQuery = $this->usageBaseQuery()->selectRaw('COUNT(*)');
        $servicesSubQuery = $this->usageBaseQuery()->selectRaw('COUNT(DISTINCT church_service_items.church_service_id)');
        $lastUsedDateSubQuery = $this->usageBaseQuery()->selectRaw('MAX(church_services.date)');

        $songs = Song::query()
            ->select('songs.*')
            ->with([
                'authors' => fn ($query) => $query->orderBy('display_name'),
            ])
            ->selectSub($usageSubQuery, 'usage_count')
            ->selectSub($servicesSubQuery, 'services_count')
            ->selectSub($lastUsedDateSubQuery, 'last_used_date')
            ->when($search !== '', function (Builder $query) use ($escapedSearch): void {
                $query->where(function (Builder $searchQuery) use ($escapedSearch): void {
                    $searchQuery->where('songs.title', 'like', "%{$escapedSearch}%")
                        ->orWhere('songs.alternate_title', 'like', "%{$escapedSearch}%")
                        ->orWhere('songs.canonical_key', 'like', "%{$escapedSearch}%")
                        ->orWhere('songs.ccli_number', 'like', "%{$escapedSearch}%")
                        ->orWhereHas('authors', fn (Builder $authorQuery) => $authorQuery->where('display_name', 'like', "%{$escapedSearch}%"));
                });
            })
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);

        return view('livewire.admin.church-services.list-songs', [
            'songs' => $songs,
            'services' => SermonService::cases(),
        ])->layout('layouts.admin', ['title' => 'Songs', 'heading' => 'Songs']);
    }

    /**
     * @return Builder<ChurchServiceItem>
     */
    private function usageBaseQuery(): Builder
    {
        return ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->whereColumn('church_service_items.song_id', 'songs.id')
            ->whereNull('church_service_items.deleted_at')
            ->where('church_service_items.type', 'songs')
            ->when(
                $this->serviceFilter !== null && $this->serviceFilter !== '',
                fn (Builder $query): Builder => $query->where('church_services.service', $this->serviceFilter)
            )
            ->when(
                $this->isIsoDate($this->dateFrom),
                fn (Builder $query): Builder => $query->whereDate('church_services.date', '>=', (string) $this->dateFrom)
            )
            ->when(
                $this->isIsoDate($this->dateTo),
                fn (Builder $query): Builder => $query->whereDate('church_services.date', '<=', (string) $this->dateTo)
            );
    }

    private function isIsoDate(?string $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
