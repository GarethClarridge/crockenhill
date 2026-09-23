<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServiceSectionSongMatchType;
use App\Jobs\MatchSongsFromTranscript;
use App\Services\ChurchService\SectionReviewFlagRecalculator;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Song\SongLyricIdentityCheck;

/**
 * Canonical rule for whether a transcript song match is confident enough to
 * present the catalogued title, and therefore whether the section's match is
 * Confirmed or merely Inferred.
 *
 * Confidence must clear the write-back threshold, and the evidence must not
 * already contradict itself. A chapter-marker mismatch scored 0.98–1.000 in
 * measured cases, while a title inferred from text inside a suspect transcript
 * block merely lets the damaged evidence corroborate itself. A title whose
 * section's own sung words clearly belong to another song is the §4.1a
 * mis-binding ({@see SongLyricIdentityCheck}). Confidence cannot arbitrate any
 * of these disputes; OCR or audited review provides independent evidence.
 *
 * Shared by the matching path ({@see MatchSongsFromTranscript}) and
 * the re-derivation path
 * ({@see SectionReviewFlagRecalculator}) so a stored
 * section and a freshly matched one answer this the same way. It is one class
 * rather than two matched implementations because the second copy had already
 * drifted: the recalculator tested confidence alone and would have promoted 13
 * marker-mismatched sections to Confirmed.
 */
class SongCatalogueTitlePolicy
{
    public const FLAG_IDENTITY_UNVERIFIED_FROM_SUSPECT_TRANSCRIPT = 'song_identity_unverified_from_suspect_transcript';

    public const FLAG_IDENTITY_CONTRADICTED_BY_LYRICS = 'song_identity_contradicted_by_lyrics';

    /**
     * Fewer than two independent sources agree on the match (operator ruling 2026-09-23: any two
     * of heard announcement, sung words, projected slides and planned order of service). Each
     * §4.1a mis-binding rested on the announcement alone.
     */
    public const FLAG_IDENTITY_SINGLE_SOURCE = 'song_identity_single_source';

    /**
     * Whether the catalogued title may replace the heard text, which is also
     * what separates a Confirmed match from an Inferred one.
     *
     * @param  array<int, string>  $reviewFlags
     */
    public static function writesCatalogueTitle(?float $confidence, array $reviewFlags): bool
    {
        if ($confidence === null) {
            return false;
        }

        if (in_array(ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH, $reviewFlags, true)) {
            return false;
        }

        if (in_array(self::FLAG_IDENTITY_UNVERIFIED_FROM_SUSPECT_TRANSCRIPT, $reviewFlags, true)) {
            return false;
        }

        if (in_array(self::FLAG_IDENTITY_CONTRADICTED_BY_LYRICS, $reviewFlags, true)) {
            return false;
        }

        if (in_array(self::FLAG_IDENTITY_SINGLE_SOURCE, $reviewFlags, true)) {
            return false;
        }

        return $confidence >= self::writebackThreshold();
    }

    /**
     * @param  array<int, string>  $reviewFlags
     */
    public static function matchTypeFor(?float $confidence, array $reviewFlags): ServiceSectionSongMatchType
    {
        return self::writesCatalogueTitle($confidence, $reviewFlags)
            ? ServiceSectionSongMatchType::Confirmed
            : ServiceSectionSongMatchType::Inferred;
    }

    public static function writebackThreshold(): float
    {
        return (float) config('media-processing.song_matching.title_writeback_min_confidence', 0.75);
    }
}
