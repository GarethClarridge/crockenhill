<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;

/** Restores displayed cue times and enforces speech-safe edges after all composition steps. */
class TranscriptCueBoundaries
{
    public const CHECK = 'shared_cue';

    public const FLAG = 'structure_shared_cue';

    public function apply(ServiceStructure $structure, ChurchServiceTranscript $transcript): ServiceStructure
    {
        $sections = [];
        foreach ($structure->sections as $section) {
            $notes = [];
            $start = $this->restore($section->startTime, 'start', $transcript, $notes);
            $end = $this->restore($section->endTime, 'end', $transcript, $notes);
            $sections[] = $section->withTimes($start, $end, array_values(array_diff($notes, $section->notes)));
        }
        // Restoration must not allocate a shared spoken line to either neighbouring item.
        foreach ($sections as $index => $section) {
            if ($index === 0 || $sections[$index - 1]->endTime <= $section->startTime) {
                continue;
            }
            $sections[$index - 1] = $sections[$index - 1]->withTimes($sections[$index - 1]->startTime, $structure->sections[$index - 1]->endTime);
            $sections[$index] = $section->withTimes($structure->sections[$index]->startTime, $section->endTime);
        }

        return $this->withSections($structure, array_values($sections));
    }

    /** @return array{structure: ServiceStructure, questions: list<array<string, mixed>>} */
    public function finish(ServiceStructure $structure, ChurchServiceTranscript $transcript): array
    {
        $sections = $structure->sections;
        $starts = $ends = [];
        foreach ($sections as $index => $section) {
            $starts[$index] = $this->outsideCue($section->startTime, 'start', $transcript);
            $ends[$index] = $this->outsideCue($section->endTime, 'end', $transcript);
        }
        $questions = [];
        $shared = [];
        foreach ($sections as $index => $right) {
            if ($index === 0 || $ends[$index - 1] <= $starts[$index]) {
                continue;
            }
            $left = $sections[$index - 1];
            if ($ends[$index - 1] === $left->endTime && $starts[$index] === $right->startTime) {
                continue;
            }
            foreach ($transcript->cues as $cue) {
                if ($cue['start'] >= $ends[$index - 1] || $cue['end'] <= $starts[$index]) {
                    continue;
                }
                $detail = sprintf('Shared transcript cue %.3f–%.3fs between %s "%s" and %s "%s": %s. Section edges retained; decide the boundary.',
                    $cue['start'], $cue['end'], $left->type->value, $left->title ?? 'untitled', $right->type->value, $right->title ?? 'untitled', $cue['text']);
                $question = ['type' => 'alignment', 'check' => self::CHECK, 'start_time' => $cue['start'], 'end_time' => $cue['end'],
                    'written' => true, 'left_section' => $left->toArray(), 'right_section' => $right->toArray(), 'cue' => $cue, 'detail' => $detail];
                $question['question_id'] = hash('sha256', json_encode([$left->type->value, $left->title, $left->startTime, $left->endTime,
                    $right->type->value, $right->title, $right->startTime, $right->endTime, $cue], JSON_THROW_ON_ERROR));
                $questions[] = $question;
                $shared[$index - 1][] = $detail;
                $shared[$index][] = $detail;
            }
            $ends[$index - 1] = $left->endTime;
            $starts[$index] = $right->startTime;
        }
        foreach ($sections as $index => $section) {
            $notes = $shared[$index] ?? [];
            if ($starts[$index] !== $section->startTime || $ends[$index] !== $section->endTime) {
                $notes[] = sprintf('Final cue-safe edges %.3f–%.3fs → %.3f–%.3fs.', $section->startTime, $section->endTime, $starts[$index], $ends[$index]);
            }
            $flags = array_values(array_diff($section->reviewFlags, [self::FLAG]));
            if (isset($shared[$index]) || ($questions !== [] && $section->type === ServiceSectionType::Sermon)) {
                $flags[] = self::FLAG;
            }
            $sections[$index] = $section->withTimes($starts[$index], $ends[$index], $notes)->withoutReviewFlags()->withReviewFlags($flags);
        }

        return ['structure' => $this->withSections($structure, $sections), 'questions' => $questions];
    }

    /** Clamp a silence proposal to the speech-free interval surrounding this edge. */
    public function snapEdge(ServiceStructureSection $section, float $proposal, string $edge, ChurchServiceTranscript $transcript): float
    {
        $contained = array_values(array_filter($transcript->cues, static fn (array $cue): bool => $cue['start'] < $section->endTime && $cue['end'] > $section->startTime));
        $time = $edge === 'end' ? $section->endTime : $section->startTime;
        $boundary = $contained === [] ? $time : ($edge === 'end' ? max(array_column($contained, 'end')) : min(array_column($contained, 'start')));
        $proposal = $edge === 'end' ? max($boundary, $proposal) : min($boundary, $proposal);
        foreach ($transcript->cues as $cue) {
            if ($edge === 'end' && $cue['start'] >= $boundary && $proposal > $cue['start']) {
                $proposal = $boundary;
            }
            if ($edge === 'start' && $cue['end'] <= $boundary && $proposal < $cue['end']) {
                $proposal = $boundary;
            }
        }

        return $proposal;
    }

    /**
     * @param  'start'|'end'  $edge
     * @param  list<string>  $notes
     */
    private function restore(float $time, string $edge, ChurchServiceTranscript $transcript, array &$notes): float
    {
        if ($time !== floor($time)) {
            return $time;
        }
        $matches = array_values(array_filter($transcript->cues, static fn (array $cue): bool => floor($cue[$edge]) === $time));
        if ($matches === []) {
            $notes[] = sprintf('%s cue boundary %.3fs has 0 matching cues; final cue safety will check it.', ucfirst($edge), $time);

            return $time;
        }
        $exact = $edge === 'end' ? max(array_column($matches, $edge)) : min(array_column($matches, $edge));
        $notes[] = sprintf('%s restored from displayed cue time %.3fs to exact cue %s %.3fs (%d matching cues).', ucfirst($edge), $time, $edge, $exact, count($matches));

        return $exact;
    }

    /** @param 'start'|'end' $edge */
    private function outsideCue(float $time, string $edge, ChurchServiceTranscript $transcript): float
    {
        do {
            $before = $time;
            foreach ($transcript->cues as $cue) {
                if ($cue['start'] < $time && $cue['end'] > $time) {
                    $time = $edge === 'end' ? max($time, $cue['end']) : min($time, $cue['start']);
                }
            }
        } while ($time !== $before);

        return $time;
    }

    /** @param list<ServiceStructureSection> $sections */
    private function withSections(ServiceStructure $structure, array $sections): ServiceStructure
    {
        return ServiceStructure::fromSections($sections, $structure->notes, $structure->model, $structure->summary, $structure->notices, $structure->chapterMarkers, $structure->sermonAbsence);
    }
}
