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
     * Resolve the review outcome for every song section that did not receive a
     * confirmed or inferred match during alignment.
     *
     * A section the segmenter detected as *speech* (a spoken lead-in — "let's
     * stand and sing hymn 196") is not a sung item: it is reclassified as
     * `Other` and its song-match state cleared, so no review path flags it.
     * Everything else is a genuine unmatched song and keeps the manual-review
     * flag + confidence penalty.
     *
     * Mutates sections in place and returns every section it touched (both the
     * reclassified and the still-flagged), for the caller to persist.
     *
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  array<int, int>  $matchedSongSectionIds
     * @return Collection<int, ServiceSection>
     */
    public function apply(EloquentCollection $sections, array $matchedSongSectionIds): Collection
    {
        return $this->unmatchedSongSections($sections, $matchedSongSectionIds)
            ->map(fn (ServiceSection $section): ServiceSection => $this->isSpokenSongAnnouncement($section)
                ? $this->reclassifyAsSpokenAnnouncement($section)
                : $this->flagUnmatchedSong($section))
            ->values();
    }

    private function flagUnmatchedSong(ServiceSection $section): ServiceSection
    {
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

        return $section;
    }

    /**
     * A `song` section the segmenter classified as speech is the spoken
     * announcement of the song, not the song itself — retype it to `Other`
     * transition filler and drop the song-match review state entirely.
     */
    private function reclassifyAsSpokenAnnouncement(ServiceSection $section): ServiceSection
    {
        $metadata = $this->metadata($section);

        $metadata['review_flags'] = array_values(array_filter(
            $this->reviewFlags($metadata),
            static fn (string $flag): bool => $flag !== 'unmatched_song_section',
        ));

        if (($metadata['review_reason'] ?? null) === 'unmatched_song_section') {
            unset($metadata['review_reason']);
        }

        $section->section_type = ServiceSectionType::Other;
        $section->song_match_type = null;
        $section->needs_manual_review = false;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);

        return $section;
    }

    private function isSpokenSongAnnouncement(ServiceSection $section): bool
    {
        return ($this->metadata($section)['detected_segment_class'] ?? null) === 'speech';
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
