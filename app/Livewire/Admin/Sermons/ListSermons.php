<?php

namespace App\Livewire\Admin\Sermons;

use App\Enums\SermonService;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;

class ListSermons extends Component
{
    use WithNotifications, WithPagination;

    private const DEFAULT_SORT_COLUMN = 'date';

    private const DEFAULT_SORT_DIRECTION = 'desc';

    private const ALLOWED_SORT_COLUMNS = [
        'title',
        'date',
        'service',
        'preacher',
        'series',
        'created_at',
        'updated_at',
    ];

    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    public string $search = '';

    public ?string $serviceFilter = null;

    public ?int $preacherFilter = null;

    public ?string $seriesFilter = null;

    public bool $hasVideoFilter = false;

    public bool $needsReviewFilter = false;

    public bool $last12Months = true;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    protected $queryString = ['search', 'serviceFilter', 'preacherFilter', 'seriesFilter', 'hasVideoFilter', 'needsReviewFilter', 'last12Months'];

    public function mount(): void
    {
        // Ensure only admins can access this component
        abort_unless(auth()->user()?->is_admin, 403, 'Unauthorized');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(Sermon $sermon): void
    {
        // Defense in depth: verify admin status
        abort_unless(auth()->user()?->is_admin, 403, 'Unauthorized');

        $sermon->delete();

        // Clear cache when sermon is deleted
        Cache::forget('admin_preacher_list');
        Cache::forget('sermon_series');

        $this->success('Sermon deleted');
    }

    /**
     * Get active preachers for filter dropdown, keyed by id.
     *
     * @return Collection<int, string>
     */
    protected function getPreachers(): Collection
    {
        return Cache::remember('admin_preacher_list', now()->addHours(24), function () {
            return Preacher::active()->orderBy('name')->pluck('name', 'id');
        });
    }

    /**
     * Get cached list of series for filter dropdown.
     */
    protected function getSeries(): Collection
    {
        return Cache::remember('sermon_series', now()->addHours(24), function () {
            return Sermon::whereNotNull('series')
                ->distinct()
                ->pluck('series')
                ->filter()
                ->sort()
                ->values();
        });
    }

    public function render()
    {
        $this->sanitizeSorting();

        $query = Sermon::query()
            ->with('preacherProfile')
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%")
                ->orWhere('preacher', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%"))
            ->when($this->serviceFilter, fn ($q) => $q->where('service', $this->serviceFilter))
            ->when($this->preacherFilter, fn ($q) => $q->where('preacher_id', $this->preacherFilter))
            ->when($this->seriesFilter, fn ($q) => $q->where('series', $this->seriesFilter))
            ->when($this->hasVideoFilter, fn ($q) => $q->whereNotNull('video_file_path'))
            ->when($this->needsReviewFilter, fn ($q) => $q->where('needs_preacher_review', true))
            ->when($this->last12Months, fn ($q) => $q->where('date', '>=', now()->subYear()))
            ->orderBy($this->sortBy, $this->sortDirection);

        $sermons = $query->paginate(20);

        $headers = [
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'service', 'label' => 'Service'],
            ['key' => 'preacher', 'label' => 'Preacher'],
            ['key' => 'series', 'label' => 'Series'],
            ['key' => 'media', 'label' => 'Media'],
        ];

        return view('livewire.admin.sermons.list-sermons', [
            'sermons' => $sermons,
            'services' => SermonService::cases(),
            'preachers' => $this->getPreachers(),
            'seriesList' => $this->getSeries(),
            'headers' => $headers,
        ])->layout('layouts.admin', ['title' => 'Sermons', 'heading' => 'Sermons']);
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
