<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ChurchServiceCanonicalConflictMetadata;
use App\Data\ChurchServiceImportMetadata;
use Carbon\CarbonImmutable;

class ChurchServiceReviewStateService
{
    /**
     * @param  array<string, mixed>  $importMetadata
     */
    public function hasOutstandingCanonicalConflict(array $importMetadata): bool
    {
        $metadata = ChurchServiceImportMetadata::fromArray($importMetadata);
        $canonicalConflict = $metadata->canonicalConflict;

        if (! $canonicalConflict instanceof ChurchServiceCanonicalConflictMetadata) {
            return false;
        }

        if ($canonicalConflict->reviewReopened !== true) {
            return false;
        }

        $detectedAt = $this->parseTimestamp($canonicalConflict->detectedAt);
        if (! $detectedAt instanceof CarbonImmutable) {
            return false;
        }

        $reviewedAt = $this->parseTimestamp($metadata->manualReview?->reviewedAt);

        return ! $reviewedAt instanceof CarbonImmutable || $reviewedAt->lt($detectedAt);
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     * @param  array<string, mixed>  $canonicalConflict
     * @return array<string, mixed>
     */
    public function withRecordedCanonicalConflict(array $importMetadata, array $canonicalConflict): array
    {
        $metadata = ChurchServiceImportMetadata::fromArray($importMetadata);
        $history = $metadata->canonicalConflictHistory;
        $history[] = $canonicalConflict;

        $data = $metadata->toArray();
        $data['canonical_conflict_history'] = $history;
        $data['canonical_conflict'] = $canonicalConflict;

        return $data;
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
