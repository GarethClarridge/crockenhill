<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\FlagSermonPartsNotExtracted;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Import\HistoricReleaseReviewHolds;

/**
 * Which runs may have their sermon text re-sliced, and which must wait.
 *
 * A re-derivation slices the saved sermon text out of the run's current
 * full-service transcript using the run's current spans. That is only a repair
 * where the spans are agreed. Where a section is still held for a reason that
 * questions the shape of the service, re-slicing would bank text to bounds
 * nobody has settled *and* withdraw the hold that says so — clearing the queue
 * while making nothing true.
 *
 * The set below is deliberately wider than the flags that sit on the sermon
 * itself. A held `song` can be an over-long section with preaching inside it
 * (P8-Q15), which moves where the sermon's material lies even though the sermon
 * section looks clean. It is the same reasoning
 * {@see HistoricReleaseReviewHolds} applies at release,
 * kept separate because the two answer different questions: that one asks
 * whether content may go public, this one whether spans are settled enough to
 * derive from.
 */
final class SermonTextRegenerationDebt
{
    /**
     * Holds that leave a run's spans in question.
     *
     * `structure_low_confidence` is included here although the release gate
     * excludes it: an uncertain section *type* can mean an `other` section is
     * really sermon, which changes what a re-slice should select. Release asks
     * whether to publish what exists; this asks whether to rewrite it.
     *
     * **Two flags were removed on 2026-09-09, and one added in their place.**
     * `structure_missing_preached_reading` and
     * `structure_sermon_boundary_material_risk` were here on the assumption that
     * any hold near a sermon questions its bounds. The codebase already held the
     * opposite ruling, twice: the validator's own docblock says of a missing
     * reading that "the sermon span extracts correctly either way", and
     * {@see SermonAutoExtractionPolicy} names both as
     * non-disqualifying — a missing reading "questions what surrounds the sermon,
     * not the sermon's own boundaries", and a boundary risk carries a recorded
     * policy to publish the inclusive span and review afterwards. Between them
     * they blocked 26 of the 58 held runs for facts that cannot move the bounds
     * being sliced.
     *
     * `sermon_parts_not_extracted` replaces them, and is the guard they were only
     * providing by coincidence. Before P8-Q15 was represented, runs 1073 and 1116
     * — both missing sermon parts — happened to carry one of the two removed flags
     * and nothing else, so relaxing the list without this addition would have
     * re-sliced a sermon to a span known to omit sixteen minutes and withdrawn the
     * staleness hold that said so. Now the run that is actually missing material
     * says so directly.
     *
     * @var list<string>
     */
    public const UnsettledSpanFlags = [
        ServiceStructureValidator::FLAG_MACRO_SECTION,
        ServiceStructureValidator::FLAG_MICRO_SECTION,
        ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED,
        ServiceStructureValidator::FLAG_LOW_CONFIDENCE,
        FlagSermonPartsNotExtracted::FLAG,
        'transcript_repetition_suspect',
        'sermon_evidence_incomplete',
    ];

    /**
     * Run id => the flag that holds it back.
     *
     * @return array<int, string>
     */
    public static function runsWithUnsettledSpans(): array
    {
        $unsettled = [];

        ServiceSection::query()
            ->where('needs_manual_review', true)
            ->select(['id', 'media_processing_log_id', 'metadata'])
            ->chunkById(500, static function ($sections) use (&$unsettled): void {
                foreach ($sections as $section) {
                    if (isset($unsettled[$section->media_processing_log_id])) {
                        continue;
                    }

                    $blocking = array_values(array_intersect(
                        $section->metadata->reviewFlags ?? [],
                        self::UnsettledSpanFlags,
                    ));

                    if ($blocking === []) {
                        continue;
                    }

                    $unsettled[$section->media_processing_log_id] = sprintf(
                        'section %d carries %s',
                        $section->id,
                        implode(', ', $blocking),
                    );
                }
            });

        return $unsettled;
    }
}
