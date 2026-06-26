<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ServiceSection;
use App\Services\Song\UnmatchedSongReviewApplicator;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Evaluates the post-alignment state of a church service to determine if manual administrative review is required.
 *
 * This calculator identifies conditions such as structure mismatches, low confidence detections, or changes
 * to late-arriving services that necessitate human oversight.
 */
class AlignmentTriggerCalculator
{
    /**
     * Capture a snapshot of alignment-relevant state from a set of sections before alignment runs.
     * Pass the result to calculate() as $beforeState for late-arrival change detection.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @return array<int, array{
     *     church_service_item_id: int|null,
     *     title: string|null,
     *     confidence: float,
     *     reading_reference: string|null,
     *     song_id: int|null,
     *     song_match_type: string|null,
     *     review_reason: string|null,
     * }>  Array keyed by section ID
     */
    public function captureAlignmentState(EloquentCollection $sections): array
    {
        return $sections->mapWithKeys(
            fn (ServiceSection $section): array => [$section->id => $this->sectionAlignmentState($section)]
        )->all();
    }

    /**
     * Compute all review triggers based on post-alignment section state.
     *
     * This method is read-only — it does not mutate any sections. Unmatched-song
     * side effects must be applied first via UnmatchedSongReviewApplicator::apply().
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  Collection<int, ServiceSection>  $unmatchedSongSections
     * @param  array<int, array{
     *     church_service_item_id: int|null,
     *     title: string|null,
     *     confidence: float,
     *     reading_reference: string|null,
     *     song_id: int|null,
     *     song_match_type: string|null,
     *     review_reason: string|null,
     * }>  $beforeState  Array keyed by section ID
     * @return array<int, 'ambiguous_sermon_detection'|'unmatched_song_sections'|'oos_structure_mismatch'|'late_oos_alignment_changed'|'too_many_low_confidence_sections'|'manual_review_sections'>
     */
    public function calculate(
        EloquentCollection $sections,
        int $structureMismatchCount,
        Collection $unmatchedSongSections,
        array $beforeState,
        bool $lateArrival
    ): array {
        $reviewTriggers = [];

        if ($this->hasAmbiguousSermon($sections)) {
            $reviewTriggers[] = 'ambiguous_sermon_detection';
        }

        if ($unmatchedSongSections->isNotEmpty()) {
            $reviewTriggers[] = 'unmatched_song_sections';
        }

        if ($structureMismatchCount > 0) {
            $reviewTriggers[] = 'oos_structure_mismatch';
        }

        if ($lateArrival && $beforeState !== $sections->mapWithKeys(
            fn (ServiceSection $section): array => [$section->id => $this->sectionAlignmentState($section)]
        )->all()) {
            $reviewTriggers[] = 'late_oos_alignment_changed';
        }

        $lowConfidenceSections = $this->lowConfidenceSections($sections);

        // The 20% threshold is a heuristic: if more than a fifth of the service sections are
        // low-confidence, the overall alignment is considered suspect and requires manual verification.
        if ($sections->count() > 0 && ($lowConfidenceSections->count() / $sections->count()) > 0.20) {
            $reviewTriggers[] = 'too_many_low_confidence_sections';
        }

        if ($sections->contains(fn (ServiceSection $section): bool => $section->needs_manual_review)) {
            $reviewTriggers[] = 'manual_review_sections';
        }

        return array_values(array_unique($reviewTriggers));
    }

    /**
     * Count sections whose resolved confidence is below the high threshold.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     */
    public function lowConfidenceSectionCount(EloquentCollection $sections): int
    {
        return $this->lowConfidenceSections($sections)->count();
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     */
    private function hasAmbiguousSermon(EloquentCollection $sections): bool
    {
        return $sections->contains(static function (ServiceSection $section): bool {
            $reason = $section->metadata['review_reason'] ?? null;

            return in_array($reason, ['secondary_sermon_candidate', 'no_high_confidence_sermon_candidate'], true);
        });
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @return Collection<int, ServiceSection>
     */
    private function lowConfidenceSections(EloquentCollection $sections): Collection
    {
        return $sections
            ->filter(fn (ServiceSection $section): bool => ServiceSectionConfidence::resolve($section->confidence, $section->metadata?->toArray() ?? []) < ServiceSectionConfidence::HIGH_THRESHOLD)
            ->values();
    }

    /**
     * @return array{
     *     church_service_item_id: int|null,
     *     title: string|null,
     *     confidence: float,
     *     reading_reference: string|null,
     *     song_id: int|null,
     *     song_match_type: string|null,
     *     review_reason: string|null,
     * }
     */
    private function sectionAlignmentState(ServiceSection $section): array
    {
        $metadata = $section->metadata?->toArray() ?? [];

        return [
            'church_service_item_id' => $section->church_service_item_id,
            'title' => $section->title,
            'confidence' => ServiceSectionConfidence::resolve($section->confidence, $metadata),
            'reading_reference' => $metadata['reading_reference'] ?? null,
            'song_id' => $metadata['song_id'] ?? null,
            'song_match_type' => $section->song_match_type?->value,
            'review_reason' => $metadata['review_reason'] ?? null,
        ];
    }
}
