<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\SongUsageOccurrence;
use App\Models\Song;
use App\Seo\SongArchiveSeoPresenter;
use App\Services\Public\PublicChurchServiceArchiveService;
use App\Services\Public\PublicSongCatalogService;
use App\Services\Public\PublicSongUsageService;
use App\Services\Song\SongVideoService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicSongListController extends Controller
{
    public function index(
        Request $request,
        SongArchiveSeoPresenter $seoPresenter,
    ): View {
        $search = is_array($request->query('q')) ? '' : (string) $request->query('q', '');
        $range = is_array($request->query('range')) ? PublicSongCatalogService::RANGE_RECENT : (string) $request->query('range', PublicSongCatalogService::RANGE_RECENT);
        $page = $request->integer('page', 1);

        return view('church.songs.index', [
            'heading' => $seoPresenter->title($search, $range, $page),
            'area' => 'church',
            'slug' => 'songs',
            'description' => $seoPresenter->description($search, $range, $page),
            'canonical_url' => $seoPresenter->canonical($search, $range, $page),
            'links' => collect(),
        ]);
    }

    public function show(
        Song $song,
        PublicSongUsageService $songUsageService,
        SongVideoService $songVideoService,
        PublicChurchServiceArchiveService $churchServiceArchive,
    ): View {
        $song->load([
            'authors' => fn ($query) => $query->select(['song_authors.id', 'song_authors.display_name'])->orderBy('display_name'),
        ]);

        $stats = $songUsageService->statsForSong($song);

        $usageHistory = $songUsageService->usageHistoryForSong($song)
            ->map(fn (SongUsageOccurrence $occurrence): array => [
                'date' => $occurrence->date,
                'service_label' => $occurrence->service?->label() ?? 'Service not recorded',
                'title' => $occurrence->title,
                'service_url' => $occurrence->churchService === null
                    ? null
                    : $churchServiceArchive->publicUrlFor($occurrence->churchService),
            ]);

        $displayVideo = $songVideoService->getDisplayVideoForSong($song);
        $videoUrl = $displayVideo ? $songVideoService->getVideoUrl($displayVideo) : null;

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
            'videoUrl' => $videoUrl,
        ]);
    }
}
