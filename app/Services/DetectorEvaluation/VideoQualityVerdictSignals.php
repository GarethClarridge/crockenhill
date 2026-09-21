<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorSignal;
use App\Enums\DetectorSurface;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;

/**
 * Reads the dead-picture detector's verdict on a run's sermon.
 *
 * **This surface reports the detector's verdict, never the effective outcome.**
 * The stored verdicts already carry human corrections — seven prior approvals
 * were moved to `needs_review` on 2026-09-16, and 867 was deliberately left
 * `approved` so the one measured false positive would not be buried — and
 * `video_visibility_override` can force a video shown or hidden regardless of
 * what the detector concluded. Scoring the effective state would credit the
 * detector for a person's repair: a wrongly rejected video that an operator
 * force-shows reads as a good outcome, so the metric would look healthiest
 * exactly where a human is compensating for the detector. The override is
 * carried in `context` instead, where a report can show it without it
 * contaminating precision.
 *
 * **This is the one surface with an explicit negative.** Section flags and
 * transcript blocks infer "not flagged" from absence; here `approved` is a
 * positive record that the detector looked and found the picture sound, while
 * `unassessed` is the absence. H10 therefore gets a true detector-negative
 * population from this surface rather than an inferred one — which is why
 * {@see self::isDetectorNegative()} insists on `approved` rather than treating
 * "no rejection" as clean.
 */
class VideoQualityVerdictSignals
{
    /**
     * The verdict recorded against this run's sermon, or null when there is no
     * sermon or it was never assessed.
     *
     * An unassessed sermon is unknown, not sound.
     *
     * @return list<DetectorSignal>|null
     */
    public function for(MediaProcessingLog $run): ?array
    {
        $sermon = $run->sermon;

        $status = $sermon instanceof Sermon ? $sermon->video_quality_status : null;

        // Null and Unassessed are the same claim — nothing looked at this
        // picture — and neither may be read as "sound".
        if ($status === null || $status === SermonVideoQualityStatus::Unassessed) {
            return null;
        }

        $reason = $sermon->video_quality_reason;

        if ($status === SermonVideoQualityStatus::Approved || $reason === null || $reason === '') {
            return [];
        }

        return [
            DetectorSignal::forStoredSignal(
                surface: DetectorSurface::VideoQualityVerdict,
                signal: $reason,
                runId: (int) $run->id,
                sermonId: (int) $sermon->id,
                held: $status === SermonVideoQualityStatus::Rejected,
                context: [
                    'status' => $status->value,
                    'visibility_override' => $this->override($sermon)->value,
                    'assessed_at' => $sermon->video_quality_assessed_at?->toIso8601String(),
                ],
            ),
        ];
    }

    /**
     * Whether this run's sermon is usable as detector-negative evidence: the
     * detector looked at the picture and approved it.
     */
    public function isDetectorNegative(MediaProcessingLog $run): bool
    {
        return $run->sermon?->video_quality_status === SermonVideoQualityStatus::Approved;
    }

    public function wasAssessed(MediaProcessingLog $run): bool
    {
        return $this->for($run) !== null;
    }

    private function override(Sermon $sermon): SermonVideoVisibilityOverride
    {
        return $sermon->video_visibility_override ?? SermonVideoVisibilityOverride::Default;
    }
}
