<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServiceSectionSongMatchType;
use App\Jobs\MatchSongsFromTranscript;
use App\Services\ChurchService\SectionReviewFlagRecalculator;
use App\Services\ChurchService\Structure\ServiceStructureValidator;

/**
 * Canonical rule for whether a transcript song match is confident enough to
 * present the catalogued title, and therefore whether the section's match is
 * Confirmed or merely Inferred.
 *
 * Two independent conditions must both hold. Confidence must clear the
 * write-back threshold, and the detector must not already have contradicted
 * itself about the naming: where the validator flagged the section's songTitle
 * as disagreeing with its own chapter marker, confidence cannot arbitrate the
 * dispute. Observed mismatches scored 0.98 and 1.000, so a confidence-only test
 * waves through exactly the rows the flag exists to hold back.
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
