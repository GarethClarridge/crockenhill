<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\SermonService;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithFilterableListing;
use App\Livewire\Traits\WithSortableListing;
use App\Models\Song;
use App\Services\Song\SongUsageQuery;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListSongs extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithFilterableListing, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'usage_count';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'title',
        'usage_count',
        'last_used_date',
        'ccli_number',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?string $serviceFilter = null;

    #[Url(except: null)]
    public ?string $dateFrom = null;

    #[Url(except: null)]
    public ?string $dateTo = null;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /**
     * @return array<string, mixed>
     */
    protected function filterProperties(): array
    {
        return [
            'search' => '',
            'serviceFilter' => null,
            'dateFrom' => null,
            'dateTo' => null,
        ];
    }

    public function render(SongUsageQuery $songUsageQuery): View
    {
        $this->sanitizeSorting();
        $this->computeHasFilters();

        $search = trim($this->search);

        // canonical_key values never contain @ (the mutator strips it), so strip any @ suffix
        // from the search term before matching, mirroring Song::canonicalizeKey().
        $atPos = strpos($search, '@');
        $canonicalSearch = $atPos !== false ? trim(substr($search, 0, $atPos)) : $search;

        $escapedSearch = $this->escapeLike($search);
        $escapedCanonicalSearch = $this->escapeLike($canonicalSearch);

        $usageSubQuery = $this->usageBaseQuery($songUsageQuery)->selectRaw('COUNT(*)');
        $servicesCountSubQuery = $this->usageBaseQuery($songUsageQuery)->selectRaw('COUNT(DISTINCT service_identity)');
        $lastUsedDateSubQuery = $this->usageBaseQuery($songUsageQuery)->selectRaw('MAX(used_on)');

        /**
         * Performance Optimization: Limits retrieved columns for songs and eager-loaded
         * authors to required fields for the admin listing. This avoids loading large
         * longText columns (lyrics_xml, lyrics_plain) to reduce memory usage and DB I/O.
         */
        $songs = Song::query()
            ->select(['songs.id', 'songs.slug', 'songs.title', 'songs.alternate_title', 'songs.ccli_number', 'songs.canonical_key'])
            ->with([
                'authors' => fn ($query) => $query->select(['song_authors.id', 'song_authors.display_name'])->orderBy('display_name'),
            ])
            ->selectSub($usageSubQuery, 'usage_count')
            ->selectSub($servicesCountSubQuery, 'services_count')
            ->selectSub($lastUsedDateSubQuery, 'last_used_date')
            ->when($search !== '', function (Builder $query) use ($escapedSearch, $escapedCanonicalSearch): void {
                $query->where(function (Builder $searchQuery) use ($escapedSearch, $escapedCanonicalSearch): void {
                    $searchQuery->where('songs.title', 'like', "%{$escapedSearch}%")
                        ->orWhere('songs.alternate_title', 'like', "%{$escapedSearch}%")
                        ->orWhere('songs.canonical_key', 'like', "%{$escapedCanonicalSearch}%")
                        ->orWhere('songs.ccli_number', 'like', "%{$escapedSearch}%")
                        ->orWhereHas('authors', fn (Builder $authorQuery) => $authorQuery->where('display_name', 'like', "%{$escapedSearch}%"));
                });
            })
            ->orderBy($this->sortBy, $this->sortDirection === 'desc' ? 'desc' : 'asc')
            ->paginate(20);

        return view('livewire.admin.church-services.list-songs', [
            'songs' => $songs,
            'services' => SermonService::cases(),
        ])->layout('layouts.admin', ['title' => 'Songs', 'heading' => 'Songs']);
    }

    private function usageBaseQuery(SongUsageQuery $songUsageQuery): QueryBuilder
    {
        return $songUsageQuery->occurrences(publicOnly: false)
            ->whereColumn('song_usage_occurrences.song_id', 'songs.id')
            ->when(
                $this->serviceFilter !== null && $this->serviceFilter !== '',
                fn (QueryBuilder $query): QueryBuilder => $query->where('reported_service', $this->serviceFilter)
            )
            ->when(
                $this->isIsoDate($this->dateFrom),
                fn (QueryBuilder $query): QueryBuilder => $query->whereDate('used_on', '>=', (string) $this->dateFrom)
            )
            ->when(
                $this->isIsoDate($this->dateTo),
                fn (QueryBuilder $query): QueryBuilder => $query->whereDate('used_on', '<=', (string) $this->dateTo)
            );
    }

    private function isIsoDate(?string $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
