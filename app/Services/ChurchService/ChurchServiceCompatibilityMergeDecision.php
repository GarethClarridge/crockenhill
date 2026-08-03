<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;

/**
 * Livestream-derived and pre-WP1 services retain the established merge-review
 * workflow until the corpus is reprojected under WP6.
 *
 * The decision reads source records rather than `church_services.source`,
 * because the projection persister writes that column from its own source
 * summary — keying on it would let the new pipeline flip a service back onto
 * the compatibility path it had just left.
 */
class ChurchServiceCompatibilityMergeDecision
{
    public function usesCompatibilityMerge(ChurchService $churchService): bool
    {
        // Compared in SQL deliberately: `pluck` would hydrate `source` through the
        // model's enum cast, and matching those instances against a backing string
        // is a silent false.
        return ! $churchService->sourceRecords()->exists()
            || $churchService->sourceRecords()
                ->where('source', ChurchServiceSource::Livestream->value)
                ->exists();
    }
}
