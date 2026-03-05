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
            return Sermon::whereNotNull('series')
                ->where('series', '!=', '')
                ->distinct()
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
     * @return array<int, string>
     */
    public function getSeriesForDisplay(): array
    {
        $series = $this->getExistingSeries();
        sort($series);

        return $series;
    }
}
