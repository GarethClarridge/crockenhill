<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

class ChurchServiceReviewStateService
{
    /**
     * @param  array<string, mixed>  $importMetadata
     */
    public function hasOutstandingCanonicalConflict(array $importMetadata): bool
    {
        $canonicalConflict = $importMetadata['canonical_conflict'] ?? null;

        if (! is_array($canonicalConflict)) {
            return false;
        }

        if (($canonicalConflict['review_reopened'] ?? false) !== true) {
            return false;
        }

        $detectedAt = $this->parseTimestamp($canonicalConflict['detected_at'] ?? null);
        if (! $detectedAt instanceof CarbonImmutable) {
            return false;
        }

        $reviewedAt = $this->parseTimestamp(data_get($importMetadata, 'manual_review.reviewed_at'));

        return ! $reviewedAt instanceof CarbonImmutable || $reviewedAt->lt($detectedAt);
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     * @param  array<string, mixed>  $canonicalConflict
     * @return array<string, mixed>
     */
    public function withRecordedCanonicalConflict(array $importMetadata, array $canonicalConflict): array
    {
        $history = $importMetadata['canonical_conflict_history'] ?? [];

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $canonicalConflict;
        $importMetadata['canonical_conflict_history'] = $history;
        $importMetadata['canonical_conflict'] = $canonicalConflict;

        return $importMetadata;
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
