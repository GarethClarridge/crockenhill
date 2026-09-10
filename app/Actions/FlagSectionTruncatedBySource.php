<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Models\ServiceSection;
use App\Support\SectionReviewFlagPolicy;

/**
 * Hold a section whose content was cut off by the end of its own recording.
 *
 * P8-Q17. Structure detection placed 18 section ends past the media that holds
 * them, because the transcript it read had been lengthened by a hallucinated
 * final cue (see {@see ChurchServiceTranscript::fromCues()}). Clamping
 * the bound back to the measured duration makes the row honest, and that is all
 * it does: it does not make the content whole. §3704 is "O Church Arise" as a
 * 23.4-second clip because the recording stopped mid-hymn, and its boundary
 * evidence had already recorded `release_eligible`. A clamp alone would have
 * left it eligible and merely renumbered.
 *
 * So the clamp and this hold are two halves of one answer: the media decides the
 * bound, and an operator decides whether what survives inside it is publishable.
 *
 * **Raised from the recorded claim, not the current bound.** The screen preserves
 * the detector's original end at `source_bounds.recorded_end` before clamping,
 * and the overrun is always measured from that. Deriving it from `end_time`
 * instead looks equivalent and inverts the flag on its second run: a clamped
 * section ends exactly at the measured duration, so the very repair that proves
 * the truncation would report none and withdraw the hold it had just earned.
 *
 * The hold still withdraws itself for the right reason. Should a restage supply
 * a source long enough to contain the recorded claim, the screen restores the
 * bound, drops the record and re-invokes this with no overrun.
 *
 * @see FlagSermonPartsNotExtracted for the sibling holding a sermon whose media
 *   omits a part the plan names; this one holds a section whose media ran out.
 */
class FlagSectionTruncatedBySource
{
    /** The recording ended before this section's content did. */
    public const FLAG = 'section_truncated_by_source';

    /**
     * Sub-second overruns are rounding between a float bound and an FFprobe
     * duration, not lost content. All eighteen of the corpus's overruns exceed
     * 16 seconds and the next largest is 0.01, so nothing sits near this line.
     */
    private const TOLERANCE_SECONDS = 1.0;

    /**
     * Raise or withdraw the hold, returning whether the section changed.
     *
     * Types with no structural-uncertainty review — welcome, notices, prayer,
     * `other` — are never held. Seventeen of the eighteen overruns are a closing
     * `other` at `not_applicable`: filler that publishes nothing, whose invented
     * tail the clamp removes outright and about which an operator can do
     * nothing. Holding those would grow the queue by seventeen rows that name no
     * decision. The one section this leaves is the one that can reach the public.
     */
    public function __invoke(ServiceSection $section, float $overrunSeconds): bool
    {
        $owed = $overrunSeconds > self::TOLERANCE_SECONDS
            && $section->section_type->requiresStructuralUncertaintyReview();

        $metadata = $section->metadata?->toArray() ?? [];
        $flags = array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));

        $without = array_values(array_filter($flags, static fn (string $flag): bool => $flag !== self::FLAG));
        $metadata['review_flags'] = $owed ? [...$without, self::FLAG] : $without;

        $needsReview = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );

        if (in_array(self::FLAG, $flags, true) === $owed && $needsReview === $section->needs_manual_review) {
            return false;
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = $needsReview;
        $section->save();

        return true;
    }
}
