<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * @deprecated Use AlignmentTriggerCalculator and UnmatchedSongReviewApplicator directly.
 * @see AlignmentTriggerCalculator
 * @see UnmatchedSongReviewApplicator
 *
 * Kept as a thin compatibility shim for any callers that inject this class.
 * The coordinator (OosAlignmentService) now uses the split classes directly.
 */
class ServiceSectionReviewTriggerEvaluator
{
    public function __construct(
        private readonly UnmatchedSongReviewApplicator $unmatchedSongApplicator,
        private readonly AlignmentTriggerCalculator $triggerCalculator,
    ) {}

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @return array<int, array<string, mixed>>
     */
    public function captureAlignmentState(EloquentCollection $sections): array
    {
        return $this->triggerCalculator->captureAlignmentState($sections);
    }

    /**
     * Applies unmatched-song side effects to the section collection and returns triggers.
     *
     * WARNING: UnmatchedSongReviewApplicator mutates the sections in place (confidence
     * penalty, review flags). Calling evaluate() more than once on the same collection
     * will compound those mutations. Do not call evaluate() and then separately call
     * unmatchedSongSectionCount() on the same mutated collection — the count will be
     * correct but the confidence/flag state will have been applied only once by evaluate().
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, int>  $matchedSongSectionIds
     * @param  array<int, array<string, mixed>>  $beforeState
     * @return array<int, string>
     */
    public function evaluate(
        EloquentCollection $sections,
        array $matchedSongSectionIds,
        int $structureMismatchCount,
        array $beforeState,
        bool $lateArrival
    ): array {
        $unmatchedSongSections = $this->unmatchedSongApplicator->apply($sections, $matchedSongSectionIds);

        return $this->triggerCalculator->calculate(
            $sections,
            $structureMismatchCount,
            $unmatchedSongSections,
            $beforeState,
            $lateArrival
        );
    }

    /**
     * Returns the count of song sections not in the matched IDs list.
     *
     * Read-only — does not apply any side effects. Safe to call independently of evaluate().
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, int>  $matchedSongSectionIds
     */
    public function unmatchedSongSectionCount(EloquentCollection $sections, array $matchedSongSectionIds): int
    {
        return $sections
            ->filter(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::SONG)
            ->reject(fn (ServiceSection $section): bool => in_array($section->id, $matchedSongSectionIds, true))
            ->count();
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     */
    public function lowConfidenceSectionCount(EloquentCollection $sections): int
    {
        return $this->triggerCalculator->lowConfidenceSectionCount($sections);
    }
}
