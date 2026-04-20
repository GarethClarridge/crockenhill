<?php

declare(strict_types=1);

namespace App\Livewire\Sermons;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\SermonScriptureFilter;
use App\Repositories\SermonRepository;
use App\Support\BibleCanon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read array<int, array{id:int, name:string}> $preacherOptions
 * @property-read array<int, array{id:string, name:string}> $seriesOptions
 * @property-read array<int, array{id:string, name:string}> $bookOptions
 * @property-read array<int, array{id:int, name:string}> $chapterOptions
 * @property-read Collection<int, string> $enabledBooks
 * @property-read Collection<int, int> $enabledChapters
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

    public function render(SermonRepository $sermonRepository): View
    {
        /** @var LengthAwarePaginator<int, \App\Models\Sermon> $sermons */
        $sermons = $sermonRepository->publicBrowseQuery(
            book: $this->bookFilter,
            chapter: $this->chapterFilter,
            preacherId: $this->preacherFilter,
            series: $this->seriesFilter,
        )->paginate(24);

        return view('livewire.sermons.browse-sermons', [
            'bookOptions' => $this->bookOptions,
            'chapterOptions' => $this->chapterOptions,
            'preacherOptions' => $this->preacherOptions,
            'seriesOptions' => $this->seriesOptions,
            'activeFilterLabels' => $this->activeFilterLabels($this->preacherOptions, $this->seriesOptions),
            'sermons' => $sermons,
            'hasActiveFilters' => $this->hasActiveFilters(),
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
     * @return array<int, array{id:int, name:string}>
     */
    #[Computed]
    public function preacherOptions(): array
    {
        return Preacher::getForPublicList()
            ->map(fn (Preacher $preacher): array => ['id' => $preacher->id, 'name' => $preacher->name])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:string, name:string}>
     */
    #[Computed]
    public function seriesOptions(): array
    {
        return collect(app(SermonRepository::class)->getSeriesForDisplay())
            ->map(fn (string $series): array => ['id' => $series, 'name' => $series])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:string, name:string}>
     */
    #[Computed]
    public function bookOptions(): array
    {
        return app(BibleCanon::class)->bookOptions($this->enabledBooks);
    }

    /**
     * @return array<int, array{id:int, name:string}>
     */
    #[Computed]
    public function chapterOptions(): array
    {
        if ($this->bookFilter === null) {
            return [];
        }

        return app(BibleCanon::class)->chapterOptions($this->bookFilter, $this->enabledChapters);
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function enabledBooks(): Collection
    {
        return $this->scriptureFilterOptionQuery()
            ->select('bible_book')
            ->distinct()
            ->pluck('bible_book');
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

        return $this->scriptureFilterOptionQuery()
            ->where('bible_book', $this->bookFilter)
            ->select('bible_chapter')
            ->distinct()
            ->orderBy('bible_chapter')
            ->pluck('bible_chapter');
    }

    /**
     * @return Builder<SermonScriptureFilter>
     */
    private function scriptureFilterOptionQuery(): Builder
    {
        return SermonScriptureFilter::query()
            ->join('sermons', 'sermons.id', '=', 'sermon_scripture_filters.sermon_id')
            ->where('sermons.content_type', SermonContentType::Sermon->value)
            ->when($this->preacherFilter, fn (Builder $query): Builder => $query->where('sermons.preacher_id', $this->preacherFilter))
            ->when($this->seriesFilter, fn (Builder $query): Builder => $query->where('sermons.series', $this->seriesFilter));
    }

    /**
     * @param  array<int, array{id:int, name:string}>  $preacherOptions
     * @param  array<int, array{id:string, name:string}>  $seriesOptions
     * @return array<int, string>
     */
    private function activeFilterLabels(array $preacherOptions, array $seriesOptions): array
    {
        $labels = [];

        if ($this->bookFilter !== null) {
            $labels[] = $this->chapterFilter !== null
                ? $this->bookFilter.' '.$this->chapterFilter
                : $this->bookFilter;
        }

        if ($this->preacherFilter !== null) {
            $labels[] = $this->findOptionName($preacherOptions, $this->preacherFilter) ?? 'Selected preacher';
        }

        if ($this->seriesFilter !== null) {
            $labels[] = $this->findOptionName($seriesOptions, $this->seriesFilter) ?? $this->seriesFilter;
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
