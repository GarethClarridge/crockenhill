<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

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
        $importMetadata = $churchService->import_metadata?->toArray() ?? [];

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
        $normalizedColumns = $this->reviewStateService->normalizedReviewColumns($importMetadata);

        $churchService->forceFill([
            'needs_review' => $reviewTriggers !== []
                || $needsSectionReview
                || $this->hasImportReviewSignal($churchService, $importMetadata)
                || filled($churchService->review_reason),
            'import_metadata' => $importMetadata,
            ...$normalizedColumns,
        ])->saveQuietly();
    }

    /**
     * Recompute service-level review from persisted, non-superseded section state.
     *
     * Drops the structure-derived review triggers whenever no live section still
     * needs manual review — including orphaned legacy triggers that a strip
     * allow-list can never enumerate — then defers to sync() for the needs_review
     * latch. sync() still preserves the import-confidence and review_reason signals,
     * so import-band and canonical-conflict services stay flagged. Idempotent:
     * re-running against an already-reconciled service is a no-op.
     */
    public function reconcileServiceReview(ChurchService $churchService): void
    {
        $sections = ServiceSection::query()
            ->forService($churchService)
            ->notSuperseded()
            ->get();

        $hasActionableSection = $sections->contains(
            fn (ServiceSection $section): bool => $section->needs_manual_review
        );

        $existingTriggers = $churchService->import_metadata->reviewTriggers ?? [];
        $reviewTriggers = $hasActionableSection ? $existingTriggers : [];

        $this->sync($churchService, $sections, $reviewTriggers);
    }

    /**
     * Open service-level review when any of the run's sections still needs
     * manual review — without rewriting review triggers and without ever
     * closing review.
     *
     * The LLM structure path uses this on OoS-backed services, where
     * projection early-returns before its own needs_review propagation and no
     * alignment pass owns the trigger list; closing review stays with sync()
     * and the operators.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     */
    public function openReviewFromSections(ChurchService $churchService, EloquentCollection $sections): void
    {
        if ($churchService->needs_review) {
            return;
        }

        $needsSectionReview = $sections->contains(
            fn (ServiceSection $section): bool => $section->needs_manual_review
        );

        if (! $needsSectionReview) {
            return;
        }

        $churchService->forceFill(['needs_review' => true])->saveQuietly();
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
