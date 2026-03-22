<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChurchServiceCanonicalConflictState;
use App\Models\ChurchService;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ChurchServiceReviewSynchronizer
{
    public function __construct(
        private readonly ChurchServiceReviewStateService $reviewStateService,
    ) {}

    /**
     * Rewrite import_metadata.review_triggers and recompute needs_review on the church service.
     *
     * Preserves import-confidence and canonical-conflict review reopening behavior.
     * Uses saveQuietly() to suppress model events — this is intentional and must be preserved.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, string>  $reviewTriggers
     */
    public function sync(ChurchService $churchService, EloquentCollection $sections, array $reviewTriggers): void
    {
        $importMetadata = $churchService->importMetadataData()->toArray();

        if ($reviewTriggers === []) {
            unset($importMetadata['review_triggers']);
        } else {
            $importMetadata['review_triggers'] = array_values($reviewTriggers);
        }

        // Defensive fallback: ensure service-level review stays open whenever any section
        // still requires manual review, even if the evaluator omits a trigger by mistake.
        $needsSectionReview = $sections->contains(
            fn (ServiceSection $section): bool => $section->needs_manual_review
        );
        $normalizedColumns = $this->reviewStateService->normalizedColumns($importMetadata, $churchService->source);

        $churchService->forceFill([
            'needs_review' => $reviewTriggers !== []
                || $needsSectionReview
                || $this->hasImportReviewSignal($churchService, $importMetadata)
                || $normalizedColumns['canonical_conflict_state'] === ChurchServiceCanonicalConflictState::REOPENED->value,
            'import_metadata' => $importMetadata,
            ...$normalizedColumns,
        ])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function hasImportReviewSignal(ChurchService $churchService, array $importMetadata): bool
    {
        if ($churchService->source === 'manual') {
            return false;
        }

        $confidenceScore = $importMetadata['confidence_score'] ?? null;
        if (! is_numeric($confidenceScore)) {
            return false;
        }

        $reviewThreshold = (float) config('service-tracking.email_parsing.review_threshold', 0.75);
        $autoImportThreshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        $score = (float) $confidenceScore;

        return $score >= $reviewThreshold && $score < $autoImportThreshold;
    }
}
