<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Support\SectionReviewFlagPolicy;

/**
 * Re-derives a section's review state from its own persisted metadata, so
 * services processed before a policy change stop carrying stale review flags.
 *
 * Pure re-derivation — never re-runs the LLM pipeline; it only reads the
 * section's stored review_flags and transcript song-match confidence. Returns
 * the column changes needed to bring the row in line with current policy, or an
 * empty array when nothing changes (idempotent).
 */
class SectionReviewFlagRecalculator
{
    /**
     * @return array<string, mixed>
     */
    public function updatesFor(ServiceSection $section): array
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $reviewFlags = is_array($metadata['review_flags'] ?? null)
            ? array_values(array_filter($metadata['review_flags'], 'is_string'))
            : [];

        // A `song` section the segmenter detected as speech that never matched a
        // song is a spoken announcement, not a sung item — retype it to `Other`
        // filler and drop the song-match review state so it stops asking to be
        // confirmed as a song. Stripping the stale flag is essential: left in
        // place, a later recompute would re-derive needs_manual_review from it.
        if ($this->isSpokenSongAnnouncement($section, $metadata, $reviewFlags)) {
            return $this->spokenAnnouncementUpdates($metadata, $reviewFlags);
        }

        $updates = [];

        $needsManualReview = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $reviewFlags,
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );

        if ($section->needs_manual_review !== $needsManualReview) {
            $updates['needs_manual_review'] = $needsManualReview;
        }

        $matchConfidence = $metadata['transcript_song_match']['confidence'] ?? null;
        $writebackThreshold = (float) config('media-processing.song_matching.title_writeback_min_confidence', 0.75);

        if (
            $section->section_type === ServiceSectionType::Song
            && $section->song_match_type === ServiceSectionSongMatchType::Inferred
            && is_numeric($matchConfidence)
            && (float) $matchConfidence >= $writebackThreshold
        ) {
            $updates['song_match_type'] = ServiceSectionSongMatchType::Confirmed;
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $reviewFlags
     */
    private function isSpokenSongAnnouncement(ServiceSection $section, array $metadata, array $reviewFlags): bool
    {
        if ($section->section_type !== ServiceSectionType::Song) {
            return false;
        }

        if (($metadata['detected_segment_class'] ?? null) !== 'speech') {
            return false;
        }

        return $section->song_match_type === ServiceSectionSongMatchType::Unmatched
            || in_array('unmatched_song_section', $reviewFlags, true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $reviewFlags
     * @return array<string, mixed>
     */
    private function spokenAnnouncementUpdates(array $metadata, array $reviewFlags): array
    {
        $metadata['review_flags'] = array_values(array_filter(
            $reviewFlags,
            static fn (string $flag): bool => $flag !== 'unmatched_song_section',
        ));

        if (($metadata['review_reason'] ?? null) === 'unmatched_song_section') {
            unset($metadata['review_reason']);
        }

        return [
            'section_type' => ServiceSectionType::Other,
            'song_match_type' => null,
            'needs_manual_review' => false,
            'metadata' => ServiceSectionMetadata::fromArray($metadata),
        ];
    }
}
