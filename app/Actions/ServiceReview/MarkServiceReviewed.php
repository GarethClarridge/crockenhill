<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Models\ChurchService;

class MarkServiceReviewed
{
    /**
     * Clear the service-level review flag and record audit metadata.
     */
    public function execute(ChurchService $service, int $userId): void
    {
        $importMetadata = $service->importMetadataData()->toArray();
        $importMetadata['manual_review'] = [
            'reviewed_at' => now()->toIso8601String(),
            'reviewed_by_user_id' => $userId,
        ];
        unset($importMetadata['canonical_conflict']);

        $service->forceFill([
            'needs_review' => false,
            'import_metadata' => $importMetadata,
        ])->save();
    }
}
