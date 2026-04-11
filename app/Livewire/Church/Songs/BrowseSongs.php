<?php

declare(strict_types=1);

namespace App\Livewire\Church\Songs;

use App\Services\PublicSongCatalogService;
use App\Services\SongLyricSnippetBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class BrowseSongs extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $range = PublicSongCatalogService::RANGE_ALL;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRange(): void
    {
        $this->resetPage();
    }

    public function render(PublicSongCatalogService $catalogService, SongLyricSnippetBuilder $snippetBuilder): View
    {
        $normalizedRange = $catalogService->normalizeRange($this->range);
        $tokens = $catalogService->tokenize($this->search);

        /** @var LengthAwarePaginator<int, \App\Models\Song> $songs */
        $songs = $catalogService->query($normalizedRange, $this->search)
            ->paginate(24)
            ->withQueryString();

        // Build lyric snippets only when a search is active and only for songs
        // whose match may be in the lyrics (not already explained by title alone).
        $snippetsBySongId = [];

        if ($tokens->isNotEmpty()) {
            foreach ($songs as $song) {
                $titleLower = mb_strtolower($song->title.' '.($song->alternate_title ?? ''));
                $authorsLower = mb_strtolower($song->authors->pluck('display_name')->implode(' '));
                $ccliLower = mb_strtolower($song->ccli_number ?? '');

                // Determine whether any token is unaccounted for outside lyrics.
                $hasNonLyricMatch = $tokens->every(function (string $token) use ($titleLower, $authorsLower, $ccliLower): bool {
                    return str_contains($titleLower, $token)
                        || str_contains($authorsLower, $token)
                        || str_contains($ccliLower, $token);
                });

                // Only show snippets when the lyrics contributed to the match.
                if (! $hasNonLyricMatch && $song->lyrics_plain !== null) {
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
