<?php

declare(strict_types=1);

namespace App\Support;

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
     * @var list<string>
     */
    public const UnsettledSpanFlags = [
        ServiceStructureValidator::FLAG_MACRO_SECTION,
        ServiceStructureValidator::FLAG_MICRO_SECTION,
        ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED,
        ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK,
        ServiceStructureValidator::FLAG_MISSING_PREACHED_READING,
        ServiceStructureValidator::FLAG_LOW_CONFIDENCE,
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
