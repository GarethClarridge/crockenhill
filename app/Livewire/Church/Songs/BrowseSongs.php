<?php

declare(strict_types=1);

namespace App\Livewire\Church\Songs;

use App\Models\Song;
use App\Presenters\SongItemListPresenter;
use App\Services\PublicSongCatalogService;
use App\Services\SongLyricSnippetBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read string $seoTitle
 * @property-read array<string, mixed> $jsonLdData
 * @property-read LengthAwarePaginator<int, Song> $songs
 */
class BrowseSongs extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'recent')]
    public string $range = PublicSongCatalogService::RANGE_RECENT;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRange(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function seoTitle(): string
    {
        if ($this->search) {
            return "Search: {$this->search} | Songs";
        }

        return $this->range === PublicSongCatalogService::RANGE_RECENT
            ? 'Recent Songs'
            : 'All Songs';
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function jsonLdData(): array
    {
        return app(SongItemListPresenter::class)->toItemList($this->songs->getCollection());
    }

    /**
     * @return LengthAwarePaginator<int, Song>
     */
    #[Computed]
    public function songs(): LengthAwarePaginator
    {
        $normalizedRange = app(PublicSongCatalogService::class)->normalizeRange($this->range);

        return app(PublicSongCatalogService::class)->query($normalizedRange, $this->search)
            ->paginate(24)
            ->withQueryString();
    }

    public function render(PublicSongCatalogService $catalogService, SongLyricSnippetBuilder $snippetBuilder): View
    {
        $normalizedRange = $catalogService->normalizeRange($this->range);
        $tokens = $catalogService->tokenize($this->search);

        /** @var LengthAwarePaginator<int, Song> $songs */
        $songs = $this->songs;

        // Build lyric snippets only when a search is active and only for songs
        // whose match may be in the lyrics (not already explained by title alone).
        $snippetsBySongId = [];

        if ($tokens->isNotEmpty()) {
            foreach ($songs as $song) {
                if ($song->lyrics_plain === null) {
                    continue;
                }

                // Show snippets whenever the lyrics contain a match for any token,
                // regardless of whether the title or authors also matched.
                // This covers lyric-only, author-only, and mixed title+lyric matches.
                if ($snippetBuilder->hasLyricMatch($song->lyrics_plain, $tokens)) {
                    $snippets = $snippetBuilder->buildSnippets($song->lyrics_plain, $tokens);

                    if ($snippets !== []) {
                        $snippetsBySongId[$song->id] = $snippets;
                    }
                }
            }
        }

        return view('livewire.church.songs.browse-songs', [
            'songs' => $songs,
            'selectedRange' => $normalizedRange,
            'snippetsBySongId' => $snippetsBySongId,
            'hasActiveSearch' => $tokens->isNotEmpty(),
        ]);
    }
}
