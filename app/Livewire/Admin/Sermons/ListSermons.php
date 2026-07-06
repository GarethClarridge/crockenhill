<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sermons;

use App\Enums\SermonService;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithAdminDelete;
use App\Livewire\Traits\WithFilterableListing;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\Public\PreacherListCache;
use App\Services\Public\SermonRepository;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListSermons extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithAdminDelete, WithFilterableListing, WithNotifications, WithPagination, WithSortableListing;

    private SermonViewPresenter $sermonViewPresenter;

    public function boot(SermonViewPresenter $sermonViewPresenter): void
    {
        $this->sermonViewPresenter = $sermonViewPresenter;
    }

    protected const DEFAULT_SORT_COLUMN = 'date';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'title',
        'date',
        'service',
        'preacher',
        'series',
        'created_at',
        'updated_at',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?string $serviceFilter = null;

    #[Url(except: null)]
    public ?int $preacherFilter = null;

    #[Url(except: null)]
    public ?string $seriesFilter = null;

    #[Url(except: false)]
    public bool $hasVideoFilter = false;

    #[Url(except: false)]
    public bool $needsReviewFilter = false;

    #[Url(except: true)]
    public bool $last12Months = true;

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
            'preacherFilter' => null,
            'seriesFilter' => null,
            'hasVideoFilter' => false,
            'needsReviewFilter' => false,
            'last12Months' => true,
        ];
    }

    /**
     * Remove the specified sermon from storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function delete(Sermon $sermon): void
    {
        $this->adminDelete(
            model: $sermon,
            logAction: 'Sermon deleted by admin',
            logFields: [
                'sermon_id' => $sermon->id,
                'title' => $this->sanitizeForLog((string) $sermon->title),
            ],
        );

        $this->success($sermon->content_type->label().' deleted');
    }

    /**
     * @return Collection<int, string>
     */
    protected function getPreachers(): Collection
    {
        return app(PreacherListCache::class)->forAdminList();
    }

    /**
     * @return Collection<int, string>
     */
    protected function getSeries(): Collection
    {
        return collect(app(SermonRepository::class)->getSeriesForDisplay());
    }

    public function render(): View
    {
        $this->sanitizeSorting();
        $this->computeHasFilters();

        $escapedSearch = $this->escapeLike(trim($this->search));

        $query = Sermon::query()
            ->select(['id', 'title', 'date', 'service', 'preacher', 'preacher_id', 'series', 'reference', 'scripture_passage_id', 'needs_preacher_review', 'audio_file_path', 'video_file_path', 'slug', 'transcript_file_path', 'content_type', 'updated_at'])
            ->with([
                'preacherProfile:id,name,slug,image_path',
                'scripturePassage:id,display_reference,normalized_reference',
            ])
            ->when($this->search !== '', function ($query) use ($escapedSearch): void {
                $searchPattern = "%{$escapedSearch}%";

                $query->where(function ($sub) use ($searchPattern): void {
                    $sub->where('title', 'like', $searchPattern)
                        ->orWhere('preacher', 'like', $searchPattern)
                        ->orWhereHas('preacherProfile', fn ($preacherQuery) => $preacherQuery->where('name', 'like', $searchPattern))
                        ->orWhere('reference', 'like', $searchPattern)
                        ->orWhereHas('scripturePassage', fn ($passageQuery) => $passageQuery
                            ->where('display_reference', 'like', $searchPattern)
                            ->orWhere('normalized_reference', 'like', $searchPattern));
                });
            })
            ->when($this->serviceFilter, fn ($q) => $q->where('service', $this->serviceFilter))
            ->when($this->preacherFilter, fn ($q) => $q->where('preacher_id', $this->preacherFilter))
            ->when($this->seriesFilter, fn ($q) => $q->where('series', $this->seriesFilter))
            ->when($this->hasVideoFilter, fn ($q) => $q->whereNotNull('video_file_path'))
            ->when($this->needsReviewFilter, fn ($q) => $q->where('needs_preacher_review', true))
            ->when($this->last12Months, fn ($q) => $q->last12Months());

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        if ($this->sortBy === 'preacher') {
            $query->orderByPreacherName($direction);
        } else {
            $query->orderBy($this->sortBy, $direction);
        }

        $sermons = $query->paginate(20);

        /**
         * Performance Optimization: Pre-warm the internal memoization caches of the
         * presenter before Blade iterates over the collection. This avoids redundant
         * container lookups and logic when rendering preacher names and scripture
         * references for each row.
         */
        $this->sermonViewPresenter->preWarmForAdminList($sermons->getCollection());

        $headers = [
            ['key' => 'title', 'label' => 'Title', 'sortable' => true],
            ['key' => 'date', 'label' => 'Date', 'sortable' => true],
            ['key' => 'service', 'label' => 'Service', 'sortable' => true],
            ['key' => 'preacher', 'label' => 'Preacher', 'sortable' => true],
            ['key' => 'series', 'label' => 'Series', 'sortable' => true],
            ['key' => 'media', 'label' => 'Media', 'sortable' => false],
        ];

        return view('livewire.admin.sermons.list-sermons', [
            'sermons' => $sermons,
            'services' => SermonService::cases(),
            'preachers' => $this->getPreachers(),
            'seriesList' => $this->getSeries(),
            'headers' => $headers,
            'sermonViewPresenter' => $this->sermonViewPresenter,
        ])->layout('layouts.admin', ['title' => 'Sermons & Talks', 'heading' => 'Sermons & Talks']);
    }
}
