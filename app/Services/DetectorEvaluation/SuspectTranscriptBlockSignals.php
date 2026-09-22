<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorSignal;
use App\Data\SuspectTranscriptBlock;
use App\Enums\DetectorSurface;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;

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
        return $this->signals($run, array_values($run->serviceSections()
            ->whereIn('section_type', [ServiceSectionType::Sermon, ServiceSectionType::ChildrensTalk])
            ->get()->all()));
    }

    /** @return list<DetectorSignal>|null */
    public function forSection(MediaProcessingLog $run, ServiceSection $section): ?array
    {
        return $this->signals($run, [$section], scoped: true);
    }

    /** @return list<DetectorSignal>|null */
    public function forSermon(MediaProcessingLog $run, int $sermonId): ?array
    {
        $sections = $run->serviceSections()->get()->filter(
            static fn (ServiceSection $section): bool => $section->published_sermon_id === $sermonId
                || ($run->sermon_id === $sermonId && $section->section_type === ServiceSectionType::Sermon),
        )->all();

        return $sections === [] ? null : $this->signals($run, array_values($sections), scoped: true);
    }

    /**
     * A run-level block is held only when every affected spoken section is
     * held. Scoped cases use only their own delivered spans and hold state.
     *
     * @param  list<ServiceSection>  $sections
     * @return list<DetectorSignal>|null
     */
    private function signals(MediaProcessingLog $run, array $sections, bool $scoped = false): ?array
    {
        $blocks = $run->recordedTranscriptSuspectBlocks();

        if ($blocks === null) {
            return null;
        }

        $signals = [];

        foreach ($blocks as $block) {
            $suspect = SuspectTranscriptBlock::fromArray($block);
            $affected = array_values(array_filter($sections, fn (ServiceSection $section): bool => $this->overlaps($run, $section, $suspect)));

            if ($scoped && $affected === []) {
                continue;
            }

            $signals[] = DetectorSignal::forStoredSignal(
                surface: DetectorSurface::SuspectTranscriptBlock,
                signal: $suspect->reason,
                runId: (int) $run->id,
                sectionId: $scoped && count($affected) === 1 ? (int) $affected[0]->id : null,
                sermonId: $scoped && count($affected) === 1 ? $affected[0]->published_sermon_id : null,
                start: $suspect->start,
                end: $suspect->end,
                held: $affected !== [] && array_filter($affected, static fn (ServiceSection $section): bool => ! $section->needs_manual_review) === [],
            );
        }

        return $signals;
    }

    private function overlaps(MediaProcessingLog $run, ServiceSection $section, SuspectTranscriptBlock $block): bool
    {
        $spans = $section->section_type === ServiceSectionType::Sermon ? $run->recordedSermonExtractionSpans() : null;
        $spans ??= [['start' => (float) $section->start_time, 'end' => (float) $section->end_time]];

        foreach ($spans as $span) {
            if ($block->overlaps($span['start'], $span['end'])) {
                return true;
            }
        }

        return false;
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
