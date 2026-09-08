<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Support\SectionReviewFlagPolicy;
use App\Support\SongCatalogueTitlePolicy;

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
     * Flags that ask about a section's alignment to a printed *song* item, and
     * so mean nothing once the section is retyped away from `song`.
     *
     * Only `unmatched_song_section` was stripped originally, which left
     * `song_alignment_inferred` behind on a section that is no longer a song.
     * That residue is not inert: the policy reads it as review-worthy, so the
     * two passes disagreed about rows a retype had deliberately cleared, and
     * {@see SectionStructureFlagRederiver} had to carry a note explaining why it
     * declined to act on them. The retype owns this, so it strips the whole set.
     *
     * @var list<string>
     */
    public const SONG_ALIGNMENT_FLAGS = [
        'unmatched_song_section',
        'song_alignment_inferred',
        'song_name_reference_only',
        'song_title_marker_mismatch',
    ];

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

        // A section retyped away from `song` before the strip above covered the
        // whole set keeps flags that ask about song alignment, and the retype
        // cannot revisit it: isSpokenSongAnnouncement() requires `song`, so once
        // retyped nothing matches it again. Strip the residue where it sits.
        if ($section->section_type !== ServiceSectionType::Song
            && array_intersect($reviewFlags, self::SONG_ALIGNMENT_FLAGS) !== []) {
            return $this->songAlignmentResidueUpdates($section, $metadata, $reviewFlags);
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

        // Promotion asks the same question the matching job asked, so it must
        // ask it the same way: confidence alone would re-confirm the very
        // sections a marker mismatch holds back, which score 0.95–1.00 precisely
        // because confidence was never what demoted them.
        if (
            $section->section_type === ServiceSectionType::Song
            && $section->song_match_type === ServiceSectionSongMatchType::Inferred
            && is_numeric($matchConfidence)
            && SongCatalogueTitlePolicy::writesCatalogueTitle((float) $matchConfidence, $reviewFlags)
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
     * Drop song-alignment flags from a section that is no longer a song, and
     * re-derive its review state from whatever genuinely remains.
     *
     * Deliberately re-derives rather than forcing `false`: a section may hold
     * other flags that still warrant review on their own terms, and this pass
     * has no standing to withdraw those.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $reviewFlags
     * @return array<string, mixed>
     */
    private function songAlignmentResidueUpdates(
        ServiceSection $section,
        array $metadata,
        array $reviewFlags,
    ): array {
        $remaining = array_values(array_filter(
            $reviewFlags,
            static fn (string $flag): bool => ! in_array($flag, self::SONG_ALIGNMENT_FLAGS, true),
        ));

        $metadata['review_flags'] = $remaining;

        if (in_array($metadata['review_reason'] ?? null, self::SONG_ALIGNMENT_FLAGS, true)) {
            unset($metadata['review_reason']);
        }

        $needsManualReview = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $remaining,
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );

        return [
            'needs_manual_review' => $needsManualReview,
            'metadata' => ServiceSectionMetadata::fromArray($metadata),
        ];
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
            static fn (string $flag): bool => ! in_array($flag, self::SONG_ALIGNMENT_FLAGS, true),
        ));

        if (in_array($metadata['review_reason'] ?? null, self::SONG_ALIGNMENT_FLAGS, true)) {
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
