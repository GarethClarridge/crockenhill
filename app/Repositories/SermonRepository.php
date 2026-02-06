<?php

namespace App\Repositories;

use App\Models\Sermon;
use Illuminate\Support\Facades\Log;

class SermonRepository
{
    /**
     * Get all distinct sermon series from database
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
}
