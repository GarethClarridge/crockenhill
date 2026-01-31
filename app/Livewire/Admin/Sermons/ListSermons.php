<?php

namespace App\Livewire\Admin\Sermons;

use App\Enums\SermonService;
use App\Models\Sermon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class ListSermons extends Component
{
    use WithPagination, Toast;

    public string $search = '';
    public ?string $serviceFilter = null;
    public ?string $preacherFilter = null;
    public ?string $seriesFilter = null;
    public bool $hasVideoFilter = false;
    public bool $last12Months = true;
    public string $sortBy = 'date';
    public string $sortDirection = 'desc';

    protected $queryString = ['search', 'serviceFilter', 'preacherFilter', 'seriesFilter', 'hasVideoFilter', 'last12Months'];

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
        Cache::forget('sermon_preachers');
        Cache::forget('sermon_series');

        $this->success('Sermon deleted');
    }

    /**
     * Get cached list of preachers for filter dropdown.
     */
    protected function getPreachers(): Collection
    {
        return Cache::remember('sermon_preachers', now()->addHours(24), function () {
            return Sermon::distinct()
                ->pluck('preacher')
                ->filter()
                ->sort()
                ->values();
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
        $query = Sermon::query()
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%")
                ->orWhere('preacher', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%"))
            ->when($this->serviceFilter, fn ($q) => $q->where('service', $this->serviceFilter))
            ->when($this->preacherFilter, fn ($q) => $q->where('preacher', $this->preacherFilter))
            ->when($this->seriesFilter, fn ($q) => $q->where('series', $this->seriesFilter))
            ->when($this->hasVideoFilter, fn ($q) => $q->whereNotNull('video_file_path'))
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
        ])->layout('components.layouts.admin', ['title' => 'Sermons']);
    }
}
