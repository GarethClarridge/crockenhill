<?php

declare(strict_types=1);

namespace App\Livewire\Sermons;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Repositories\SermonRepository;
use App\Support\BibleCanon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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

    public function render(SermonRepository $sermonRepository, BibleCanon $bibleCanon): View
    {
        $hasActiveFilters = $this->hasActiveFilters();
        $preacherOptions = Preacher::getForPublicList()
            ->map(fn (Preacher $preacher): array => ['id' => $preacher->id, 'name' => $preacher->name])
            ->values()
            ->all();
        $seriesOptions = collect($sermonRepository->getSeriesForDisplay())
            ->map(fn (string $series): array => ['id' => $series, 'name' => $series])
            ->values()
            ->all();

        /** @var Collection<int, Sermon>|Collection<string, Collection<int, Sermon>>|null $sermons */
        $sermons = $hasActiveFilters
            ? $this->filteredQuery($sermonRepository)->paginate(24)
            : $sermonRepository->getAllSermons();

        return view('livewire.sermons.browse-sermons', [
            'bookOptions' => $bibleCanon->bookOptions($this->enabledBooks()),
            'chapterOptions' => $this->bookFilter === null
                ? []
                : $bibleCanon->chapterOptions($this->bookFilter, $this->enabledChapters()),
            'preacherOptions' => $preacherOptions,
            'seriesOptions' => $seriesOptions,
            'activeFilterLabels' => $this->activeFilterLabels($preacherOptions, $seriesOptions),
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
     * @return Builder<Sermon>
     */
    private function filteredQuery(SermonRepository $sermonRepository): Builder
    {
        $query = $sermonRepository->publicSermonQuery()
            ->when($this->preacherFilter, fn (Builder $builder): Builder => $builder->where('preacher_id', $this->preacherFilter))
            ->when($this->seriesFilter, fn (Builder $builder): Builder => $builder->where('series', $this->seriesFilter));

        if ($this->bookFilter !== null) {
            $bookFilter = $this->bookFilter;
            $chapterFilter = $this->chapterFilter;

            $query->whereHas('scriptureFilters', function (Builder $builder) use ($bookFilter, $chapterFilter): void {
                $builder->where('bible_book', $bookFilter);

                if ($chapterFilter !== null) {
                    $builder->where('bible_chapter', $chapterFilter);
                }
            });
        }

        return $query
            ->orderBy('date', 'desc')
            ->orderBy('service', 'asc');
    }

    /**
     * @return Collection<int, string>
     */
    private function enabledBooks(): Collection
    {
        return $this->scriptureFilterOptionQuery()
            ->select('bible_book')
            ->distinct()
            ->pluck('bible_book');
    }

    /**
     * @return Collection<int, int>
     */
    private function enabledChapters(): Collection
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
