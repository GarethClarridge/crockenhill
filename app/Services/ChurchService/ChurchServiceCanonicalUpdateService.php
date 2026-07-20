<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

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

        $originalImportMetadata = $freshChurchService->getRawOriginal('import_metadata');
        $originalImportMetadata = is_string($originalImportMetadata) && trim($originalImportMetadata) !== ''
            ? json_decode($originalImportMetadata, true)
            : [];
        $originalImportMetadata = is_array($originalImportMetadata) ? $originalImportMetadata : [];
        $importMetadataData = $freshChurchService->import_metadata;
        $reviewedPreviously = is_string($importMetadataData?->manualReview?->reviewedAt);
        $shouldReopenReview = $reviewedPreviously && ($changes !== [] || $conflicts !== []);
        $importMetadata = $importMetadataData?->toArray() ?? [];

        if ($conflicts !== [] || ($beforeSnapshot !== [] && $changes !== [])) {
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
        $normalizedColumns = $this->reviewStateService->normalizedColumns($importMetadata);

        if (
            $needsReview !== $freshChurchService->needs_review
            || $importMetadata !== $originalImportMetadata
            || $this->hasNormalizedStateChanges($freshChurchService, $normalizedColumns)
        ) {
            $freshChurchService->forceFill([
                'needs_review' => $needsReview,
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
