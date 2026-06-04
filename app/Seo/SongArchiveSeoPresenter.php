<?php

declare(strict_types=1);

namespace App\Seo;

use App\Services\PublicSongCatalogService;

class SongArchiveSeoPresenter
{
    public function __construct(private readonly PublicSongCatalogService $catalog) {}

    public function title(?string $search, string $range, int $page = 1): string
    {
        if (filled($search)) {
            $base = "{$search} | Songs";
        } else {
            $base = $this->catalog->normalizeRange($range) === PublicSongCatalogService::RANGE_RECENT
                ? 'Recent Songs'
                : 'All Songs';
        }

        if ($page > 1) {
            return "{$base} (Page {$page})";
        }

        return $base;
    }

    public function description(?string $search, string $range, int $page = 1): string
    {
        if (filled($search)) {
            $desc = "Browse songs matching '{$search}' at Crockenhill Baptist Church.";
        } else {
            $desc = $this->catalog->normalizeRange($range) === PublicSongCatalogService::RANGE_RECENT
                ? 'Browse the songs most recently sung at Crockenhill Baptist Church.'
                : 'Browse the full song catalogue of Crockenhill Baptist Church.';
        }

        if ($page > 1) {
            return "{$desc} - Page {$page}";
        }

        return $desc;
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
