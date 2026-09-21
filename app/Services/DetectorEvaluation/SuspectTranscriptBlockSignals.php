<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorSignal;
use App\Data\SuspectTranscriptBlock;
use App\Enums\DetectorSurface;
use App\Models\MediaProcessingLog;

/**
 * Reads what the transcript screens recorded on a run.
 *
 * **The null case is the whole reason this class is careful.** A run's recorded
 * blocks are `null` when it was never screened and `[]` when it was screened and
 * found clear, and {@see MediaProcessingLog::recordedTranscriptSuspectBlocks()}
 * keeps those apart deliberately — runs that completed before the screen existed
 * carry no stamp, and the 2026-09-09 correctness review found looping sermons
 * among exactly that group.
 *
 * H10 measures miss rate by reviewing what a detector did *not* flag, so an
 * unscreened run silently counted as detector-negative would be a run the
 * detector never looked at being scored as a run it cleared. That would inflate
 * apparent recall with the very services most likely to be defective. Hence
 * {@see self::for()} returning null rather than an empty list, and
 * {@see self::isDetectorNegative()} being a separate, explicit question.
 *
 * (Measured 2026-09-21: all 286 unflagged eligible historic runs are screened
 * clear and none is unscreened, so H10's denominator is sound as it stands. The
 * distinction is kept because that is a fact about today's corpus, not a
 * property of the data model.)
 */
class SuspectTranscriptBlockSignals
{
    /**
     * The blocks this run's transcript screen recorded, or null when the run was
     * never screened.
     *
     * Null is unknown, never clean.
     *
     * @return list<DetectorSignal>|null
     */
    public function for(MediaProcessingLog $run): ?array
    {
        $blocks = $run->recordedTranscriptSuspectBlocks();

        if ($blocks === null) {
            return null;
        }

        $signals = [];

        foreach ($blocks as $block) {
            $suspect = SuspectTranscriptBlock::fromArray($block);

            $signals[] = DetectorSignal::forStoredSignal(
                surface: DetectorSurface::SuspectTranscriptBlock,
                signal: $suspect->reason,
                runId: (int) $run->id,
                start: $suspect->start,
                end: $suspect->end,
                held: true,
            );
        }

        return $signals;
    }

    /**
     * Whether this run is usable as detector-negative evidence for the
     * transcript screens: screened, and nothing found.
     *
     * An unscreened run answers false, because nothing looked at it.
     */
    public function isDetectorNegative(MediaProcessingLog $run): bool
    {
        return $this->for($run) === [];
    }

    /**
     * Whether the run was screened at all, whatever the screen found.
     */
    public function wasScreened(MediaProcessingLog $run): bool
    {
        return $this->for($run) !== null;
    }
}
