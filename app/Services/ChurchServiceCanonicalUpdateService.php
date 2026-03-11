<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChurchServiceItemSource;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;

class ChurchServiceCanonicalUpdateService
{
    public function __construct(
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceReviewStateService $reviewStateService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $beforeSnapshot
     * @param  array{conflicts?:array<int, array<string, mixed>>}  $syncResult
     */
    public function finalize(
        ChurchService $churchService,
        array $beforeSnapshot,
        ChurchServiceItemSource|string $incomingSource,
        array $syncResult = [],
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

        $conflicts = $syncResult['conflicts'] ?? [];

        $reviewedPreviously = is_string(data_get($freshChurchService->import_metadata, 'manual_review.reviewed_at'));
        $shouldReopenReview = $reviewedPreviously && ($changes !== [] || $conflicts !== []);
        $importMetadata = is_array($freshChurchService->import_metadata) ? $freshChurchService->import_metadata : [];

        if ($changes !== [] || $conflicts !== []) {
            $canonicalConflict = [
                'detected_at' => now()->toIso8601String(),
                'incoming_source' => $resolvedSource->value,
                'review_reopened' => $shouldReopenReview,
                'reviewed_previously' => $reviewedPreviously,
                'canonical_changed' => $changes !== [],
                'changes' => $changes,
                'conflicts' => $conflicts,
            ];
            $importMetadata = $this->reviewStateService->withRecordedCanonicalConflict($importMetadata, $canonicalConflict);

            if ($shouldReopenReview) {
                $manualReview = is_array($importMetadata['manual_review'] ?? null) ? $importMetadata['manual_review'] : [];
                $manualReview['reopened_at'] = now()->toIso8601String();
                $manualReview['reopened_by_source'] = $resolvedSource->value;
                $importMetadata['manual_review'] = $manualReview;
            }
        }

        $needsReview = $freshChurchService->needs_review
            || $conflicts !== []
            || $shouldReopenReview
            || $this->reviewStateService->hasOutstandingCanonicalConflict($importMetadata);

        if (
            $needsReview !== $freshChurchService->needs_review
            || $importMetadata !== (is_array($freshChurchService->import_metadata) ? $freshChurchService->import_metadata : [])
        ) {
            $freshChurchService->forceFill([
                'needs_review' => $needsReview,
                'import_metadata' => $importMetadata,
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
}
