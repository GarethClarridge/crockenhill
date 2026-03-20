<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Song;
use App\Services\PublicSongUsageService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicSongListController extends Controller
{
    public function index(Request $request, PublicSongUsageService $songUsageService): View
    {
        $this->abortIfDisabled();

        $range = $songUsageService->normalizeRange($request->string('range')->toString());
        $songs = $songUsageService->query($range)
            ->paginate(24)
            ->withQueryString();

        return view('church.songs.index', [
            'heading' => 'Songs',
            'area' => 'church',
            'slug' => 'songs',
            'description' => 'Browse the songs most often sung at Crockenhill Baptist Church.',
            'links' => collect(),
            'selectedRange' => $range,
            'songs' => $songs,
        ]);
    }

    public function show(Song $song, PublicSongUsageService $songUsageService): View
    {
        $this->abortIfDisabled();

        $song->load([
            'authors' => fn ($query) => $query->orderBy('display_name'),
        ]);

        $stats = $songUsageService->statsForSong($song);
        $usageHistory = $songUsageService->usageHistoryForSong($song);

        return view('church.songs.show', [
            'heading' => $song->title,
            'area' => 'church',
            'slug' => 'songs',
            'description' => 'Lyrics and recent usage for '.$song->title.' at Crockenhill Baptist Church.',
            'links' => collect(),
            'song' => $song,
            'usageCount' => $stats['usage_count'],
            'lastSungDate' => $stats['last_sung_date'],
            'usageHistory' => $usageHistory,
        ]);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
