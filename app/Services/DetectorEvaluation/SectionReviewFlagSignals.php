<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorSignal;
use App\Enums\DetectorSurface;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\RetiredSectionReviewFlags;
use App\Support\SectionReviewFlagPolicy;

/**
 * Reads what the structure and song detectors recorded on a run's sections.
 *
 * Read-only by construction: it reports the flags the pipeline already wrote and
 * never re-runs detection, so the harness cannot disagree with production about
 * what a detector did. Re-deriving flags here would produce a second opinion
 * from newer code and score it as though it were the stored one.
 *
 * Retired flags are skipped rather than reported as uncatalogued. A retired flag
 * is a question whose raise site has been deleted, already accounted for by
 * {@see RetiredSectionReviewFlags}; surfacing it as a coverage gap would send
 * someone looking for a detector that was removed on purpose.
 *
 * `held` is taken from the section's own `needs_manual_review`, not inferred
 * from the flag: {@see SectionReviewFlagPolicy} demotes several
 * flags on section types where they imply no operator action, and a flag that
 * fired without holding has contained nothing.
 */
class SectionReviewFlagSignals
{
    /**
     * Every flag recorded on this run's sections.
     *
     * @return list<DetectorSignal>
     */
    public function for(MediaProcessingLog $run): array
    {
        $signals = [];

        foreach ($run->serviceSections()->get() as $section) {
            $signals = [...$signals, ...$this->forSection($run, $section)];
        }

        return $signals;
    }

    /**
     * @return list<DetectorSignal>
     */
    public function forSection(MediaProcessingLog $run, ServiceSection $section): array
    {
        $signals = [];

        foreach ($section->metadata->reviewFlags ?? [] as $flag) {
            if (RetiredSectionReviewFlags::isRetired($flag)) {
                continue;
            }

            $signals[] = DetectorSignal::forStoredSignal(
                surface: DetectorSurface::SectionReviewFlag,
                signal: $flag,
                runId: (int) $run->id,
                sectionId: (int) $section->id,
                start: $section->start_time,
                end: $section->end_time,
                held: (bool) $section->needs_manual_review,
            );
        }

        return $signals;
    }
}
