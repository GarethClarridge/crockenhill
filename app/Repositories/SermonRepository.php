<?php

namespace App\Repositories;

use App\Models\Sermon;
use Illuminate\Support\Facades\Log;

class SermonRepository
{
    /**
     * Get all distinct sermon series from database
     *
     * @return array<int, string>
     */
    public function getExistingSeries(): array
    {
        try {
            return Sermon::query()
                ->whereSermon()
                ->whereNotNull('series')
                ->where('series', '!=', '')
                ->distinct()
                ->orderBy('series')
                ->pluck('series')
                ->filter()
                ->values()
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('Failed to retrieve existing series', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get all distinct sermon series sorted alphabetically for display in UI.
     *
     * Performance Optimization: Caches the series list for 24 hours using flexible cache
     * to reduce redundant distinct DB queries on listing and admin pages.
     *
     * @return array<int, string>
     */
    public function getSeriesForDisplay(): array
    {
        return \Illuminate\Support\Facades\Cache::flexible('sermon_series', [86400, 172800], function () {
            $series = $this->getExistingSeries();
            sort($series);

            return $series;
        });
    }
}
