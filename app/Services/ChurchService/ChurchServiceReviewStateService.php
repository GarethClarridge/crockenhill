<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceCanonicalConflictMetadata;
use App\Data\ChurchServiceImportMetadata;
use App\Enums\ChurchServiceCanonicalConflictReason;
use App\Enums\ChurchServiceCanonicalConflictState;
use App\Enums\ChurchServiceReviewState;
use App\Models\ChurchService;
use Carbon\CarbonImmutable;

class ChurchServiceReviewStateService
{
    /**
     * @param  ChurchService|array<string, mixed>  $source
     */
    public function hasOutstandingCanonicalConflict(ChurchService|array $source): bool
    {
        if ($source instanceof ChurchService) {
            return $source->canonical_conflict_state === ChurchServiceCanonicalConflictState::Reopened;
        }

        return $this->normalizedColumns($source)['canonical_conflict_state']
            === ChurchServiceCanonicalConflictState::Reopened->value;
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     * @return array{
     *     review_state: string,
     *     manual_reviewed_at: string|null,
     *     manual_reviewed_by_user_id: int|null,
     *     manual_review_reopened_at: string|null,
     *     manual_review_reopened_by_source: string|null,
     *     canonical_conflict_state: string,
     *     canonical_conflict_detected_at: string|null,
     *     canonical_conflict_incoming_source: string|null,
     *     canonical_conflict_reviewed_previously: bool|null,
     *     canonical_conflict_canonical_changed: bool|null,
     *     canonical_conflict_reason: string|null
     * }
     */
    public function normalizedColumns(array $importMetadata, ?string $fallbackIncomingSource = null): array
    {
        $metadata = ChurchServiceImportMetadata::fromArray($importMetadata);

        $reviewState = match (true) {
            is_string($metadata->manualReview?->reopenedAt) => ChurchServiceReviewState::Reopened,
            is_string($metadata->manualReview?->reviewedAt) => ChurchServiceReviewState::Reviewed,
            default => ChurchServiceReviewState::NotReviewed,
        };

        $canonicalConflict = $metadata->canonicalConflict;
        $reviewedAt = $this->parseTimestamp($metadata->manualReview?->reviewedAt);
        $detectedAt = $this->parseTimestamp($canonicalConflict?->detectedAt);
        $incomingSource = $this->normalizeOptionalString($canonicalConflict?->incomingSource)
            ?? $this->normalizeOptionalString($fallbackIncomingSource)
            ?? 'unknown';
        $canonicalConflictState = match (true) {
            ! $canonicalConflict instanceof ChurchServiceCanonicalConflictMetadata => ChurchServiceCanonicalConflictState::None,
            ! $detectedAt instanceof CarbonImmutable => ChurchServiceCanonicalConflictState::None,
            $canonicalConflict->reviewReopened === true
                && $reviewedAt instanceof CarbonImmutable
                && ! $reviewedAt->lt($detectedAt) => ChurchServiceCanonicalConflictState::None,
            $canonicalConflict->reviewReopened === true => ChurchServiceCanonicalConflictState::Reopened,
            default => ChurchServiceCanonicalConflictState::Detected,
        };

        $canonicalConflictDetectedAt = $canonicalConflictState === ChurchServiceCanonicalConflictState::None
            ? null
            : $detectedAt?->toIso8601String();
        $canonicalConflictIncomingSource = $canonicalConflictState === ChurchServiceCanonicalConflictState::None
            ? null
            : $incomingSource;
        $canonicalConflictReviewedPreviously = null;
        $canonicalConflictCanonicalChanged = null;
        $canonicalConflictReason = null;

        if (
            $canonicalConflictState !== ChurchServiceCanonicalConflictState::None
            && $canonicalConflict instanceof ChurchServiceCanonicalConflictMetadata
        ) {
            $canonicalConflictReviewedPreviously = $canonicalConflict->reviewedPreviously;
            $canonicalConflictCanonicalChanged = $canonicalConflict->canonicalChanged;
            $canonicalConflictReason = $this->canonicalConflictReason(
                $canonicalConflict->changes,
                $canonicalConflict->conflicts
            )->value;
        }

        return [
            'review_state' => $reviewState->value,
            'manual_reviewed_at' => $metadata->manualReview?->reviewedAt,
            'manual_reviewed_by_user_id' => $metadata->manualReview?->reviewedByUserId,
            'manual_review_reopened_at' => $metadata->manualReview?->reopenedAt,
            'manual_review_reopened_by_source' => $metadata->manualReview?->reopenedBySource,
            'canonical_conflict_state' => $canonicalConflictState->value,
            'canonical_conflict_detected_at' => $canonicalConflictDetectedAt,
            'canonical_conflict_incoming_source' => $canonicalConflictIncomingSource,
            'canonical_conflict_reviewed_previously' => $canonicalConflictReviewedPreviously,
            'canonical_conflict_canonical_changed' => $canonicalConflictCanonicalChanged,
            'canonical_conflict_reason' => $canonicalConflictReason,
        ];
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

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    private function canonicalConflictReason(array $changes, array $conflicts): ChurchServiceCanonicalConflictReason
    {
        $hasChanges = $changes !== [];
        $hasConflicts = $conflicts !== [];

        return match (true) {
            $hasChanges && $hasConflicts => ChurchServiceCanonicalConflictReason::CanonicalChangedWithConflicts,
            $hasChanges => ChurchServiceCanonicalConflictReason::CanonicalChanged,
            $hasConflicts => ChurchServiceCanonicalConflictReason::ConflictsOnly,
            default => ChurchServiceCanonicalConflictReason::Unspecified,
        };
    }

    private function parseTimestamp(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
