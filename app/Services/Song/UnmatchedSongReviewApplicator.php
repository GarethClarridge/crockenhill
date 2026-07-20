<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use App\Traits\ReadsSectionMetadata;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class UnmatchedSongReviewApplicator
{
    use ReadsSectionMetadata;

    /**
     * Apply unmatched-song review flags and confidence penalties to all song sections
     * that did not receive a confirmed or inferred match during alignment.
     *
     * Mutates sections in place and returns the unmatched sections.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, int>  $matchedSongSectionIds
     * @return Collection<int, ServiceSection>
     */
    public function apply(EloquentCollection $sections, array $matchedSongSectionIds): Collection
    {
        $unmatchedSongSections = $this->unmatchedSongSections($sections, $matchedSongSectionIds);

        foreach ($unmatchedSongSections as $section) {
            $metadata = $this->metadata($section);
            $reviewFlags = $this->reviewFlags($metadata);
            $reviewFlags[] = 'unmatched_song_section';
            $metadata['review_flags'] = array_values(array_unique($reviewFlags));

            if (! array_key_exists('review_reason', $metadata)) {
                $metadata['review_reason'] = 'unmatched_song_section';
            }

            $section->needs_manual_review = true;
            $section->song_match_type = $section->song_match_type ?? ServiceSectionSongMatchType::Unmatched;
            $section->confidence = ServiceSectionConfidence::decrease(
                ServiceSectionConfidence::resolve($section->confidence, $metadata),
                0.10
            );
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        }

        return $unmatchedSongSections;
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, int>  $matchedSongSectionIds
     * @return Collection<int, ServiceSection>
     */
    private function unmatchedSongSections(EloquentCollection $sections, array $matchedSongSectionIds): Collection
    {
        return $sections
            ->filter(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Song)
            ->reject(fn (ServiceSection $section): bool => in_array($section->id, $matchedSongSectionIds, true))
            ->values();
    }
}
