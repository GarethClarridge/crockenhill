<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\ChurchServiceRollupStatus;
use App\Enums\SermonService;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithFilterableListing;
use App\Livewire\Traits\WithSortableListing;
use App\Models\ChurchService;
use App\Queries\AdminAttentionCounts;
use App\Queries\ChurchServiceRollupQuery;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListChurchServices extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithFilterableListing, WithPagination, WithSortableListing;

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

    /**
     * Columns the hub needs per row. `import_metadata` is included because the
     * run matcher harvests fallback processing ids from it (contract C2).
     */
    protected const LIST_COLUMNS = [
        'id', 'date', 'service', 'source', 'original_filename', 'needs_review', 'updated_at',
        'pending_structure_merge_source', 'import_metadata',
    ];

    /** Days either side of today considered for the "This Sunday" hero. */
    protected const HERO_WINDOW_DAYS = 7;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?string $serviceFilter = null;

    #[Url(except: null)]
    public ?bool $needsReviewFilter = null;

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
            'needsReviewFilter' => null,
        ];
    }

    public function render(ChurchServiceRollupQuery $rollupQuery, AdminAttentionCounts $attentionCounts): View
    {
        $this->sanitizeSorting();

        $this->computeHasFilters();

        $search = trim($this->search);
        $escapedSearch = $this->escapeLike($search);

        /**
         * Performance Optimization: Limits retrieved columns for church services to required fields
         * to reduce memory usage and DB I/O. Search terms are escaped to prevent LIKE injection.
         */
        $churchServices = ChurchService::query()
            ->select(self::LIST_COLUMNS)
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
            ->orderBy($this->sortBy, $this->sortDirection === 'desc' ? 'desc' : 'asc')
            ->paginate(20);

        /** @var EloquentCollection<int, ChurchService> $pageServices */
        $pageServices = $churchServices->getCollection();

        $heroCandidates = $this->heroCandidates();

        /** @var EloquentCollection<int, ChurchService> $rollupInput */
        $rollupInput = $pageServices->concat($heroCandidates)->unique('id')->values();

        $rollups = $rollupQuery->forServices($rollupInput);

        $heroService = $this->selectHeroService($heroCandidates, $rollups);

        $headers = [
            ['key' => 'date', 'label' => 'Service', 'sortable' => true],
            ['key' => 'items', 'label' => 'Items', 'sortable' => false],
            ['key' => 'source', 'label' => 'Source', 'sortable' => true],
            ['key' => 'needs_review', 'label' => 'Status', 'sortable' => true],
            ['key' => 'updated_at', 'label' => 'Uploaded', 'sortable' => true],
        ];

        return view('livewire.admin.church-services.list-church-services', [
            'churchServices' => $churchServices,
            'services' => SermonService::cases(),
            'headers' => $headers,
            'rollups' => $rollups,
            'attentionChips' => $this->attentionChips($attentionCounts->counts()),
            'heroService' => $heroService,
            'heroRollup' => $heroService !== null ? ($rollups[$heroService->id] ?? null) : null,
            'heroIsCurrent' => $heroService !== null && $this->isWithinHeroWindow($heroService),
        ])->layout('layouts.admin', ['title' => 'Services', 'heading' => 'Services']);
    }

    /**
     * @param  array{pending_emails: int, awaiting_segment_runs: int, flagged_sections: int, pending_merges: int, services_needing_review: int}  $counts
     * @return list<array{label: string, count: int, href: string}>
     */
    private function attentionChips(array $counts): array
    {
        return [
            ['label' => 'Inbound emails', 'count' => $counts['pending_emails'], 'href' => route('admin.services.inbox', ['filter' => 'emails'])],
            ['label' => 'Sermon segments', 'count' => $counts['awaiting_segment_runs'], 'href' => route('admin.services.inbox', ['filter' => 'segments'])],
            ['label' => 'Flagged sections', 'count' => $counts['flagged_sections'], 'href' => route('admin.services.inbox', ['filter' => 'sections'])],
            ['label' => 'Pending merges', 'count' => $counts['pending_merges'], 'href' => route('admin.services.inbox', ['filter' => 'services'])],
            ['label' => 'Services needing review', 'count' => $counts['services_needing_review'], 'href' => route('admin.services.inbox', ['filter' => 'services'])],
        ];
    }

    /**
     * Hero candidates: services dated within ±7 days of today, or failing
     * that the most recent past service.
     *
     * @return EloquentCollection<int, ChurchService>
     */
    private function heroCandidates(): EloquentCollection
    {
        $window = ChurchService::query()
            ->select(self::LIST_COLUMNS)
            ->withCount('items')
            ->whereBetween('date', [
                now()->subDays(self::HERO_WINDOW_DAYS)->toDateString(),
                now()->addDays(self::HERO_WINDOW_DAYS)->toDateString(),
            ])
            ->get();

        if ($window->isNotEmpty()) {
            return $window;
        }

        return ChurchService::query()
            ->select(self::LIST_COLUMNS)
            ->withCount('items')
            ->whereDate('date', '<=', now()->toDateString())
            ->orderByDesc('date')
            ->orderBy('service')
            ->limit(1)
            ->get();
    }

    /**
     * Pick the hero: closest to today, tie-breaking on needs-attention
     * (non-zero rollup attention count) first, then most recent date.
     *
     * @param  EloquentCollection<int, ChurchService>  $candidates
     * @param  array<int, array{status: ChurchServiceRollupStatus, attention_count: int, run_count: int, steps: list<array{label: string, state: string}>}>  $rollups
     */
    private function selectHeroService(EloquentCollection $candidates, array $rollups): ?ChurchService
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $today = now()->startOfDay();

        return $candidates
            ->sort(function (ChurchService $left, ChurchService $right) use ($rollups, $today): int {
                $distance = (int) abs($today->diffInDays($left->date)) <=> (int) abs($today->diffInDays($right->date));
                if ($distance !== 0) {
                    return $distance;
                }

                $leftAttention = ($rollups[$left->id]['attention_count'] ?? 0) > 0;
                $rightAttention = ($rollups[$right->id]['attention_count'] ?? 0) > 0;
                if ($leftAttention !== $rightAttention) {
                    return $rightAttention <=> $leftAttention;
                }

                return $right->date <=> $left->date;
            })
            ->first();
    }

    private function isWithinHeroWindow(ChurchService $service): bool
    {
        return (int) abs(now()->startOfDay()->diffInDays($service->date)) <= self::HERO_WINDOW_DAYS;
    }
}
