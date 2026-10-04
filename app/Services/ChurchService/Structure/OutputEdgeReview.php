<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Enums\ServiceSectionType;

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

    /**
     * Whether answering the question can change this output: by moving one of its edges, or by
     * adding a section composition joins to the sermon without touching an edge — a reading
     * before it, or the concluding prayer before the next song. A tie-break that left such a
     * section out leaves its dispute wholly outside the cut, where edges alone never see it.
     *
     * @param  array<string, mixed>  $question
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  list<float>  $songStarts
     */
    public static function concernsOutput(array $question, array $spans, array $songStarts = []): bool
    {
        if (self::touches($question, $spans)) {
            return true;
        }

        if ($spans === [] || ! is_numeric($question['start_time'] ?? null) || ! is_numeric($question['end_time'] ?? null)) {
            return false;
        }

        $outputStart = min(array_column($spans, 'start_time'));
        $outputEnd = max(array_column($spans, 'end_time'));
        $start = (float) $question['start_time'];

        return match ($question['type'] ?? null) {
            ServiceSectionType::BibleReading->value => $start < $outputStart,
            ServiceSectionType::Prayer->value => $start >= $outputEnd
                && ! array_any($songStarts, static fn (float $song): bool => $song >= $outputEnd && $song < $start),
            default => false,
        };
    }

    /** @param list<array<string, mixed>> $questions */
    public static function apply(ServiceStructure $structure, array $questions): ServiceStructure
    {
        $sections = [];
        $songStarts = array_map(static fn ($song): float => $song->startTime, $structure->sectionsOfType(ServiceSectionType::Song));
        foreach ($structure->sections as $section) {
            $flags = array_values(array_diff($section->reviewFlags, [TranscriptCueBoundaries::FLAG, ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES]));
            $span = [['start_time' => $section->startTime, 'end_time' => $section->endTime]];
            $concerns = $section->type === ServiceSectionType::Sermon
                ? static fn (array $question): bool => self::concernsOutput($question, $span, $songStarts)
                : static fn (array $question): bool => self::touches($question, $span);
            if (array_any($questions, $concerns)) {
                $flags[] = ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES;
            }
            $sections[] = $section->withoutReviewFlags()->withReviewFlags($flags);
        }

        return ServiceStructure::fromSections($sections, $structure->notes, $structure->model, $structure->summary, $structure->notices, $structure->chapterMarkers, $structure->sermonAbsence);
    }
}
