<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

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

        $updates = [];

        $needsManualReview = SectionReviewFlagPolicy::requiresManualReview($section->section_type, $reviewFlags);

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
}
