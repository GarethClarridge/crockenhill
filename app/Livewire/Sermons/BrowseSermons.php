<?php

declare(strict_types=1);

namespace App\Livewire\Sermons;

use App\Models\Preacher;
use App\Repositories\SermonRepository;
use App\Support\BibleCanon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read Collection<int, string> $enabledBooks
 * @property-read Collection<int, int> $enabledChapters
 * @property-read array<int, array{id:int, name:string}> $preacherOptions
 * @property-read array<int, array{id:string, name:string}> $seriesOptions
 */
class BrowseSermons extends Component
{
    use WithPagination;

    #[Url(as: 'book', except: null)]
    public ?string $bookFilter = null;

    #[Url(as: 'chapter', except: null)]
    public ?int $chapterFilter = null;

    #[Url(as: 'preacher', except: null)]
    public ?int $preacherFilter = null;

    #[Url(as: 'series', except: null)]
    public ?string $seriesFilter = null;

    public function mount(BibleCanon $bibleCanon): void
    {
        if ($this->bookFilter !== null && ! $bibleCanon->hasBook($this->bookFilter)) {
            $this->bookFilter = null;
        }

        if ($this->bookFilter === null) {
            $this->chapterFilter = null;

            return;
        }

        $maxChapter = $bibleCanon->chaptersInBook($this->bookFilter);

        if ($this->chapterFilter !== null && ($this->chapterFilter < 1 || $this->chapterFilter > $maxChapter)) {
            $this->chapterFilter = null;
        }
    }

    public function updatedBookFilter(): void
    {
        $this->chapterFilter = null;
        $this->resetPage();
    }

    public function updatedChapterFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPreacherFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSeriesFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'bookFilter',
            'chapterFilter',
            'preacherFilter',
            'seriesFilter',
        ]);

        $this->resetPage();
    }

    public function removeFilter(string $filter): void
    {
        if ($filter === 'book') {
            $this->bookFilter = null;
            $this->chapterFilter = null;
        } elseif ($filter === 'preacher') {
            $this->preacherFilter = null;
        } elseif ($filter === 'series') {
            $this->seriesFilter = null;
        }

        $this->resetPage();
    }

    public function render(SermonRepository $sermonRepository, BibleCanon $bibleCanon): View
    {
        $hasActiveFilters = $this->hasActiveFilters();

        /** @var LengthAwarePaginator<int, \App\Models\Sermon> $sermons */
        $sermons = $sermonRepository->publicBrowseQuery(
            book: $this->bookFilter,
            chapter: $this->chapterFilter,
            preacherId: $this->preacherFilter,
            series: $this->seriesFilter,
        )->paginate(24);

        return view('livewire.sermons.browse-sermons', [
            'bookOptions' => $bibleCanon->bookOptions($this->enabledBooks),
            'chapterOptions' => $this->bookFilter === null
                ? []
                : $bibleCanon->chapterOptions($this->bookFilter, $this->enabledChapters),
            'preacherOptions' => $this->preacherOptions,
            'seriesOptions' => $this->seriesOptions,
            'activeFilterLabels' => $this->activeFilterLabels($this->preacherOptions, $this->seriesOptions),
            'sermons' => $sermons,
            'hasActiveFilters' => $hasActiveFilters,
        ]);
    }

    private function hasActiveFilters(): bool
    {
        return $this->bookFilter !== null
            || $this->chapterFilter !== null
            || $this->preacherFilter !== null
            || $this->seriesFilter !== null;
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function enabledBooks(): Collection
    {
        return app(SermonRepository::class)->getScriptureBooks(
            preacherId: $this->preacherFilter,
            series: $this->seriesFilter,
        );
    }

    /**
     * @return Collection<int, int>
     */
    #[Computed]
    public function enabledChapters(): Collection
    {
        if ($this->bookFilter === null) {
            return collect();
        }

        return app(SermonRepository::class)->getScriptureChapters(
            book: $this->bookFilter,
            preacherId: $this->preacherFilter,
            series: $this->seriesFilter,
        );
    }

    /**
     * @return array<int, array{id:int, name:string}>
     */
    #[Computed]
    public function preacherOptions(): array
    {
        return array_map(
            fn (Preacher $preacher): array => ['id' => $preacher->id, 'name' => $preacher->name],
            Preacher::getForPublicList()->all()
        );
    }

    /**
     * @return array<int, array{id:string, name:string}>
     */
    #[Computed]
    public function seriesOptions(): array
    {
        return array_map(
            fn (string $series): array => ['id' => $series, 'name' => $series],
            app(SermonRepository::class)->getSeriesForDisplay()
        );
    }

    /**
     * @param  array<int, array{id:int, name:string}>  $preacherOptions
     * @param  array<int, array{id:string, name:string}>  $seriesOptions
     * @return array<string, string>
     */
    private function activeFilterLabels(array $preacherOptions, array $seriesOptions): array
    {
        $labels = [];

        if ($this->bookFilter !== null) {
            $labels['book'] = $this->chapterFilter !== null
                ? $this->bookFilter.' '.$this->chapterFilter
                : $this->bookFilter;
        }

        if ($this->preacherFilter !== null) {
            $labels['preacher'] = $this->findOptionName($preacherOptions, $this->preacherFilter) ?? 'Selected preacher';
        }

        if ($this->seriesFilter !== null) {
            $labels['series'] = $this->findOptionName($seriesOptions, $this->seriesFilter) ?? $this->seriesFilter;
        }

        return $labels;
    }

    /**
     * @param  array<int, array{id:int|string, name:string}>  $options
     */
    private function findOptionName(array $options, int|string $id): ?string
    {
        foreach ($options as $option) {
            if ((string) $option['id'] !== (string) $id) {
                continue;
            }

            return $option['name'];
        }

        return null;
    }
}
