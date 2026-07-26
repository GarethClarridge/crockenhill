<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceItemSource;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;

class ChurchServiceCanonicalUpdateService
{
    public const CanonicalChangedAfterReviewReason = 'Service items changed after manual review.';

    public const IncomingConflictReason = 'Incoming service data conflicted with existing items.';

    public const ChangedAndConflictedAfterReviewReason = 'Service items changed after manual review and incoming data conflicted with existing items.';

    public function __construct(
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceReviewStateService $reviewStateService,
    ) {}

    /**
     * Record the outcome of a merge against the service's review state.
     *
     * $recordReviewState is the switch a provisional pass needs. Review state here
     * is sticky by design — needs_review is ORed in and a reopened review cannot be
     * closed by a later pass — so a caller whose items are working guesses rather
     * than findings must be able to change the canonical list without leaving a
     * verdict behind. Suppressing the sync's conflicts is not enough on its own: a
     * canonical change alone reopens a settled review.
     *
     * @param  array<int, array<string, mixed>>  $beforeSnapshot
     * @param  array{conflicts?:array<int, array<string, mixed>>}  $syncResult
     */
    public function finalize(
        ChurchService $churchService,
        array $beforeSnapshot,
        ChurchServiceItemSource|string $incomingSource,
        array $syncResult = [],
        bool $recordReviewState = true,
    ): ChurchService {
        $resolvedSource = $incomingSource instanceof ChurchServiceItemSource
            ? $incomingSource
            : ChurchServiceItemSource::from($incomingSource);

        $freshChurchService = ChurchService::query()
            ->with([
                'items' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            ])
            ->findOrFail($churchService->id);

        $changes = $this->canonicalStateService->diff(
            $beforeSnapshot,
            $this->canonicalStateService->snapshot($freshChurchService),
        );

        $conflicts = $recordReviewState ? $syncResult['conflicts'] ?? [] : [];

        $originalImportMetadata = $freshChurchService->getRawOriginal('import_metadata');
        $originalImportMetadata = is_string($originalImportMetadata) && trim($originalImportMetadata) !== ''
            ? json_decode($originalImportMetadata, true)
            : [];
        $originalImportMetadata = is_array($originalImportMetadata) ? $originalImportMetadata : [];
        $importMetadataData = $freshChurchService->import_metadata;
        $reviewedPreviously = is_string($importMetadataData?->manualReview?->reviewedAt);
        $shouldReopenReview = $recordReviewState && $reviewedPreviously && ($changes !== [] || $conflicts !== []);
        $importMetadata = $importMetadataData?->toArray() ?? [];

        if ($recordReviewState && ($conflicts !== [] || ($beforeSnapshot !== [] && $changes !== []))) {
            $canonicalConflict = [
                'detected_at' => now()->toIso8601String(),
                'incoming_source' => $resolvedSource->value,
                'review_reopened' => $shouldReopenReview,
                'reviewed_previously' => $reviewedPreviously,
                'canonical_changed' => $changes !== [],
                'changes' => $changes,
                'conflicts' => $conflicts,
            ];
            $importMetadata = $this->reviewStateService->withCanonicalConflictHistory($importMetadata, $canonicalConflict);

            if ($shouldReopenReview) {
                $manualReview = is_array($importMetadata['manual_review'] ?? null) ? $importMetadata['manual_review'] : [];
                $manualReview['reopened_at'] = now()->toIso8601String();
                $manualReview['reopened_by_source'] = $resolvedSource->value;
                $importMetadata['manual_review'] = $manualReview;
            }
        }

        $needsReview = $freshChurchService->needs_review
            || $conflicts !== []
            || $shouldReopenReview;
        $reviewReason = $this->reviewReason(
            $freshChurchService->review_reason,
            $shouldReopenReview,
            $conflicts !== [],
        );
        $normalizedColumns = $this->reviewStateService->normalizedReviewColumns($importMetadata);

        if (
            $needsReview !== $freshChurchService->needs_review
            || $importMetadata !== $originalImportMetadata
            || $this->hasNormalizedStateChanges($freshChurchService, $normalizedColumns)
        ) {
            $freshChurchService->forceFill([
                'needs_review' => $needsReview,
                'review_reason' => $reviewReason,
                'import_metadata' => $importMetadata,
                ...$normalizedColumns,
            ])->saveQuietly();
        }

        if ($changes !== []) {
            event(new ChurchServiceCanonicalListChanged(
                $freshChurchService->id,
                $resolvedSource->value,
                $changes,
            ));
        }

        return $freshChurchService->fresh([
            'items' => fn ($query) => $query->orderBy('position')->orderBy('id'),
        ]) ?? $freshChurchService;
    }

    private function reviewReason(?string $existingReason, bool $reviewReopened, bool $hasConflicts): ?string
    {
        if ($reviewReopened && $hasConflicts) {
            return self::ChangedAndConflictedAfterReviewReason;
        }

        if ($reviewReopened) {
            return self::CanonicalChangedAfterReviewReason;
        }

        if ($hasConflicts) {
            return self::IncomingConflictReason;
        }

        return $existingReason;
    }

    /**
     * @param  array<string, mixed>  $normalizedColumns
     */
    private function hasNormalizedStateChanges(ChurchService $churchService, array $normalizedColumns): bool
    {
        foreach ($normalizedColumns as $column => $value) {
            $current = $churchService->getRawOriginal($column);

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            if ($current !== $value) {
                return true;
            }
        }

        return false;
    }
}
