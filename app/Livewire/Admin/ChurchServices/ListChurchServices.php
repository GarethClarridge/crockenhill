<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\ChurchServiceRollupStatus;
use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\Concerns\ManagesInboundEmailReview;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithFilterableListing;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\ChurchService;
use App\Queries\AdminAttentionCounts;
use App\Queries\ChurchServiceRollupQuery;
use App\Queries\ReviewInboxQuery;
use App\Traits\EscapesLikeWildcards;
use App\Traits\SanitizesLogData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListChurchServices extends Component
{
    use EscapesLikeWildcards;
    use ManagesInboundEmailReview;
    use SanitizesLogData;
    use WithAdminAuthorization;
    use WithFilterableListing;
    use WithNotifications;
    use WithPagination;
    use WithSortableListing;

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

    public function render(
        ChurchServiceRollupQuery $rollupQuery,
        AdminAttentionCounts $attentionCounts,
        ReviewInboxQuery $reviewInboxQuery,
    ): View {
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

        $attention = $reviewInboxQuery->build();
        $attentionTotals = $attentionCounts->counts();

        return view('livewire.admin.church-services.list-church-services', [
            'churchServices' => $churchServices,
            'services' => SermonService::cases(),
            'headers' => $headers,
            'rollups' => $rollups,
            'attentionGroups' => $this->summariseAttentionGroups($attention['groups']),
            'attentionShown' => $attention['counts']['all'],
            'attentionTotal' => $attentionCounts->total($attentionTotals),
            'heroService' => $heroService,
            'heroRollup' => $heroService !== null ? ($rollups[$heroService->id] ?? null) : null,
            'heroIsCurrent' => $heroService !== null && $this->isWithinHeroWindow($heroService),
        ])->layout('layouts.admin', ['title' => 'Services', 'heading' => 'Services']);
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function summariseAttentionGroups(array $groups): array
    {
        return array_map(function (array $group): array {
            $kindCounts = [];

            foreach ($group['items'] as $item) {
                $kind = $item['kind'];
                $kindCounts[$kind] = ($kindCounts[$kind] ?? 0) + 1;
            }

            $group['emails'] = array_values(array_filter(
                $group['items'],
                fn (array $item): bool => $item['kind'] === 'email',
            ));
            $group['summary'] = collect([
                $this->summaryPart($kindCounts['section'] ?? 0, 'section to confirm', 'sections to confirm'),
                $this->summaryPart($kindCounts['segment'] ?? 0, 'sermon segment needs choosing', 'sermon segments need choosing'),
                $this->summaryPart($kindCounts['merge'] ?? 0, 'plan conflict to resolve', 'plan conflicts to resolve'),
                $this->summaryPart($kindCounts['proposal'] ?? 0, 'evidence proposal to review', 'evidence proposals to review'),
                $this->summaryPart($kindCounts['service_flag'] ?? 0, 'service needs checking', 'service flags need checking'),
            ])->filter()->implode(' · ');

            return $group;
        }, $groups);
    }

    private function summaryPart(int $count, string $singular, string $plural): ?string
    {
        if ($count === 0) {
            return null;
        }

        return $count.' '.($count === 1 ? $singular : $plural);
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
