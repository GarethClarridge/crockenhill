<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ServiceSectionReviewTriggerEvaluator
{
    /**
     * Capture a snapshot of alignment-relevant state from a set of sections before alignment runs.
     * Pass the result to evaluate() as $beforeState for late-arrival change detection.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @return array<int, array<string, mixed>>
     */
    public function captureAlignmentState(EloquentCollection $sections): array
    {
        return $sections->mapWithKeys(
            fn (ServiceSection $section): array => [$section->id => $this->sectionAlignmentState($section)]
        )->all();
    }

    /**
     * Evaluate all §7.5 review triggers for the given sections after alignment has run.
     * Also applies unmatched-song metadata updates directly to sections in the collection.
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
        $reviewTriggers = [];

        if ($this->hasAmbiguousSermon($sections)) {
            $reviewTriggers[] = 'ambiguous_sermon_detection';
        }

        $unmatchedSongSections = $this->unmatchedSongSections($sections, $matchedSongSectionIds);
        if ($unmatchedSongSections->isNotEmpty()) {
            foreach ($unmatchedSongSections as $section) {
                $metadata = $this->sectionMetadata($section);
                $alignment = is_array($metadata['oos_alignment'] ?? null) ? $metadata['oos_alignment'] : [];

                if (($alignment['song_match_type'] ?? null) === null) {
                    $alignment['song_match_type'] = ServiceSectionSongMatchType::UNMATCHED->value;
                }

                $metadata['oos_alignment'] = $alignment;
                $reviewFlags = $this->reviewFlags($metadata);
                $reviewFlags[] = 'unmatched_song_section';
                $metadata['review_flags'] = array_values(array_unique($reviewFlags));
                if (! array_key_exists('review_reason', $metadata)) {
                    $metadata['review_reason'] = 'unmatched_song_section';
                }
                $section->needs_manual_review = true;
                $section->confidence = ServiceSectionConfidence::decrease(
                    ServiceSectionConfidence::resolve($section->confidence, $metadata),
                    0.10
                );
                $section->metadata = $metadata;
            }

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
        if ($sections->count() > 0 && ($lowConfidenceSections->count() / $sections->count()) > 0.20) {
            $reviewTriggers[] = 'too_many_low_confidence_sections';
        }

        return array_values(array_unique($reviewTriggers));
    }

    /**
     * Count song sections that have no OoS match after alignment.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, int>  $matchedSongSectionIds
     */
    public function unmatchedSongSectionCount(EloquentCollection $sections, array $matchedSongSectionIds): int
    {
        return $this->unmatchedSongSections($sections, $matchedSongSectionIds)->count();
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
     * @param  array<int, int>  $matchedSongSectionIds
     * @return Collection<int, ServiceSection>
     */
    private function unmatchedSongSections(EloquentCollection $sections, array $matchedSongSectionIds): Collection
    {
        return $sections
            ->filter(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::SONG)
            ->reject(fn (ServiceSection $section): bool => in_array($section->id, $matchedSongSectionIds, true))
            ->values();
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @return Collection<int, ServiceSection>
     */
    private function lowConfidenceSections(EloquentCollection $sections): Collection
    {
        return $sections
            ->filter(fn (ServiceSection $section): bool => ServiceSectionConfidence::resolve($section->confidence, $section->metadata) < ServiceSectionConfidence::HIGH_THRESHOLD)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionAlignmentState(ServiceSection $section): array
    {
        $metadata = $this->sectionMetadata($section);

        return [
            'church_service_item_id' => $section->church_service_item_id,
            'title' => $section->title,
            'confidence' => ServiceSectionConfidence::resolve($section->confidence, $metadata),
            'reading_reference' => $metadata['reading_reference'] ?? null,
            'song_id' => $metadata['song_id'] ?? null,
            'song_match_type' => $metadata['oos_alignment']['song_match_type'] ?? null,
            'review_reason' => $metadata['review_reason'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionMetadata(ServiceSection $section): array
    {
        return is_array($section->metadata) ? $section->metadata : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    private function reviewFlags(array $metadata): array
    {
        $flags = $metadata['review_flags'] ?? [];

        if (! is_array($flags)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $flag): ?string => is_string($flag) ? $flag : null, $flags)
        ));
    }
}
