<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Services\PublicSongCatalogService;

class SongArchiveSeoPresenter
{
    public function __construct(private readonly PublicSongCatalogService $catalog) {}

    public function title(?string $search, string $range): string
    {
        if (filled($search)) {
            return "{$search} | Songs";
        }

        return $this->catalog->normalizeRange($range) === PublicSongCatalogService::RANGE_RECENT
            ? 'Recent Songs'
            : 'All Songs';
    }

    public function description(?string $search, string $range): string
    {
        if (filled($search)) {
            return "Browse songs matching '{$search}' at Crockenhill Baptist Church.";
        }

        return $this->catalog->normalizeRange($range) === PublicSongCatalogService::RANGE_RECENT
            ? 'Browse the songs most recently sung at Crockenhill Baptist Church.'
            : 'Browse the full song catalogue of Crockenhill Baptist Church.';
    }

    public function canonical(?string $search, string $range, int $page = 1): string
    {
        $normalizedRange = $this->catalog->normalizeRange($range);

        $params = array_filter([
            'q' => filled($search) ? $search : null,
            'range' => $normalizedRange === PublicSongCatalogService::RANGE_RECENT ? null : $normalizedRange,
            'page' => $page > 1 ? $page : null,
        ], fn ($val) => $val !== null);

        return route('church.songs.index', $params);
    }
}
