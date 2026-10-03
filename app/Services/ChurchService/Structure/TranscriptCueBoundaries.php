<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;

/** Restores exact cue edges named by the prompt's whole-second timestamps before sound refinement. */
class TranscriptCueBoundaries
{
    public function apply(ServiceStructure $structure, ChurchServiceTranscript $transcript): ServiceStructure
    {
        $sections = [];
        foreach ($structure->sections as $section) {
            $notes = [];
            $start = $this->edge($section->startTime, 'start', $transcript, $notes);
            $end = $this->edge($section->endTime, 'end', $transcript, $notes);
            $sections[] = $section->withTimes($start, $end, $notes);
        }

        return ServiceStructure::fromSections(
            $sections, $structure->notes, $structure->model, $structure->summary,
            $structure->notices, $structure->chapterMarkers, $structure->sermonAbsence,
        );
    }

    /**
     * @param  'start'|'end'  $edge
     * @param  list<string>  $notes
     */
    private function edge(float $time, string $edge, ChurchServiceTranscript $transcript, array &$notes): float
    {
        if ($time !== floor($time)) {
            return $time;
        }

        $matches = array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => floor($cue[$edge]) === $time,
        ));
        if (count($matches) !== 1) {
            $notes[] = sprintf('%s cue boundary %.3fs has %d matching cues; left unchanged.', ucfirst($edge), $time, count($matches));

            return $time;
        }

        $exact = $matches[0][$edge];
        if ($exact !== $time) {
            $notes[] = sprintf('%s restored from displayed cue time %.3fs to exact cue %s %.3fs.', ucfirst($edge), $time, $edge, $exact);
        }

        return $exact;
    }
}
