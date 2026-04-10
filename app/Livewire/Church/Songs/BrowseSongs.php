<?php

declare(strict_types=1);

namespace App\Livewire\Church\Songs;

use App\Services\PublicSongCatalogService;
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

    public function render(PublicSongCatalogService $catalogService): View
    {
        $normalizedRange = $catalogService->normalizeRange($this->range);

        /** @var LengthAwarePaginator<int, \App\Models\Song> $songs */
        $songs = $catalogService->query($normalizedRange)
            ->paginate(24)
            ->withQueryString();

        return view('livewire.church.songs.browse-songs', [
            'songs' => $songs,
            'selectedRange' => $normalizedRange,
        ]);
    }
}
