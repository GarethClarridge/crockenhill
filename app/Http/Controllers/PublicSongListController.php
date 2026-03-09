<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicSongUsageService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicSongListController extends Controller
{
    public function __invoke(Request $request, PublicSongUsageService $songUsageService): View
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
            'selectedRange' => $range,
            'songs' => $songs,
        ]);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
