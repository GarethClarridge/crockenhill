<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;

/** Scope unresolved claims to the output edges they can change. */
class OutputEdgeReview
{
    /**
     * @param  array<string, mixed>  $question
     * @param  list<array{start_time: float, end_time: float}>  $spans
     */
    public static function touches(array $question, array $spans): bool
    {
        if (($question['check'] ?? null) === TranscriptCueBoundaries::CHECK || ($question['type'] ?? null) === 'sermon_absence') {
            return false;
        }
        if (($question['check'] ?? null) === TalkEdgeChecks::CHECK && is_array($question['edges'] ?? null)) {
            $times = array_map(static fn (string $edge): float => (float) $question[$edge.'_time'], $question['edges']);

            return array_any($spans, static fn (array $span): bool => array_any($times,
                static fn (float $time): bool => $span['start_time'] === $time || $span['end_time'] === $time));
        }
        $ranges = [];
        if (is_numeric($question['start_time'] ?? null) && is_numeric($question['end_time'] ?? null)) {
            $ranges[] = [(float) $question['start_time'], (float) $question['end_time']];
        }
        foreach ($question['alternatives'] ?? [] as $alternative) {
            $section = $alternative['section'] ?? null;
            if (is_array($section) && is_numeric($section['start_time'] ?? null) && is_numeric($section['end_time'] ?? null)) {
                $ranges[] = [(float) $section['start_time'], (float) $section['end_time']];
            }
        }
        if ($ranges === []) {
            return true;
        }
        foreach ($spans as $span) {
            foreach ($ranges as [$start, $end]) {
                foreach ($span as $edge) {
                    if ($edge >= $start && $edge <= $end) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $questions */
    public static function apply(ServiceStructure $structure, array $questions): ServiceStructure
    {
        $sections = [];
        foreach ($structure->sections as $section) {
            $flags = array_values(array_diff($section->reviewFlags, [TranscriptCueBoundaries::FLAG, ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES]));
            $span = [['start_time' => $section->startTime, 'end_time' => $section->endTime]];
            if (array_any($questions, static fn (array $question): bool => self::touches($question, $span))) {
                $flags[] = ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES;
            }
            $sections[] = $section->withoutReviewFlags()->withReviewFlags($flags);
        }

        return ServiceStructure::fromSections($sections, $structure->notes, $structure->model, $structure->summary, $structure->notices, $structure->chapterMarkers, $structure->sermonAbsence);
    }
}
