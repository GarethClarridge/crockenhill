<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;

/**
 * Asks about a talk edge every draft agreed on where it touches other speech.
 *
 * Agreement cannot see an error all four drafts share, and the shared errors the 2026-09-28
 * evaluation found sat at talk edges: a talk ended where a prayer, reading or notice follows with
 * no pause. The operator ruled every such edge asked (2026-09-28, plan DR6): a talk edge within
 * {@see self::JOIN_GAP_SECONDS} of a speech section. Music is not speech, and the sound stage
 * places those edges. A talk the drafts already disagree about is asked by its own question.
 * A talk ending into prayer is exempt under the operator's 2026-10-02 ruling: retaining or
 * excluding its closing prayer is acceptable. Prayer before a talk still checks its start.
 *
 * Each check is an ordinary question whose one version is the composed talk, so it holds the run
 * like any question and its answer, confirming or correcting the talk, goes through
 * {@see ServiceStructureEnsembleRulingApplier} and carries to later compositions by its scope.
 */
final class TalkEdgeChecks
{
    public const CHECK = 'talk_edge';

    /** A pause this long or longer between a talk and the next speech is a boundary the recording marks. */
    public const JOIN_GAP_SECONDS = 3.0;

    private const SPEECH_TYPES = [
        ServiceSectionType::Welcome,
        ServiceSectionType::Prayer,
        ServiceSectionType::Notices,
        ServiceSectionType::BibleReading,
        ServiceSectionType::ShortTalk,
        ServiceSectionType::Sermon,
        ServiceSectionType::Other,
    ];

    /** @param  array<int, ValidationResult>  $draws */
    public function apply(EnsembleComposition $composition, array $draws): EnsembleComposition
    {
        if ($composition->refused) {
            return $composition;
        }

        $sections = $composition->structure->sections;
        usort($sections, static fn (ServiceStructureSection $a, ServiceStructureSection $b): int => $a->startTime <=> $b->startTime);
        $voters = array_keys(array_filter($draws, static fn (ValidationResult $draw): bool => $draw->passed()));
        sort($voters);
        $asked = [...$composition->disputes, ...$composition->majorityDecisions];
        $checks = [];
        $checked = [];

        foreach ($sections as $index => $talk) {
            if ($talk->type !== ServiceSectionType::ShortTalk || $this->alreadyAsked($talk, $asked)) {
                continue;
            }

            $neighbours = [];

            foreach (['start' => $sections[$index - 1] ?? null, 'end' => $sections[$index + 1] ?? null] as $edge => $neighbour) {
                if (! $neighbour instanceof ServiceStructureSection || ! in_array($neighbour->type, self::SPEECH_TYPES, true)) {
                    continue;
                }

                if ($edge === 'end' && $neighbour->type === ServiceSectionType::Prayer) {
                    continue;
                }

                $gap = $edge === 'start' ? $talk->startTime - $neighbour->endTime : $neighbour->startTime - $talk->endTime;

                if ($gap < self::JOIN_GAP_SECONDS) {
                    $neighbours[] = ['edge' => $edge, 'type' => $neighbour->type->value, 'title' => $neighbour->title];
                }
            }

            if ($neighbours === []) {
                continue;
            }

            $check = [
                'type' => ServiceSectionType::ShortTalk->value,
                'check' => self::CHECK,
                'edges' => array_column($neighbours, 'edge'),
                'neighbours' => $neighbours,
                'start_time' => $talk->startTime,
                'end_time' => $talk->endTime,
                'written' => true,
                'supporting_slots' => $voters,
                'absent_slots' => [],
                'alternatives' => [['slots' => $voters, 'section' => $talk->withoutReviewFlags()->toArray()]],
            ];
            $check['question_id'] = hash('sha256', json_encode($check, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $checks[] = $check;
            $checked[] = [$talk->startTime, $talk->endTime];
        }

        if ($checks === []) {
            return $composition;
        }

        $flagged = array_map(static function (ServiceStructureSection $section) use ($checked): ServiceStructureSection {
            $isChecked = $section->type === ServiceSectionType::ShortTalk
                && in_array([$section->startTime, $section->endTime], $checked, true);

            return $isChecked || $section->type === ServiceSectionType::Sermon
                ? $section->withReviewFlags([ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES])
                : $section;
        }, $composition->structure->sections);
        $structure = $composition->structure;

        return new EnsembleComposition(
            new ServiceStructure($flagged, $structure->notes, $structure->model, $structure->summary, $structure->notices, $structure->chapterMarkers, $structure->sermonAbsence),
            [...$composition->disputes, ...$checks],
            $composition->provenance,
            $composition->degraded,
            $composition->refused,
            $composition->validVotes,
            $composition->degradedReviewed,
            $composition->majorityDecisions,
        );
    }

    /** @param  list<array<string, mixed>>  $asked */
    private function alreadyAsked(ServiceStructureSection $talk, array $asked): bool
    {
        foreach ($asked as $dispute) {
            if (! in_array($dispute['type'] ?? null, [ServiceSectionType::ShortTalk->value, 'alignment'], true)) {
                continue;
            }

            $spans = [];

            foreach ($dispute['alternatives'] ?? [] as $alternative) {
                if (is_array($alternative) && is_array($alternative['section'] ?? null)) {
                    $spans[] = [(float) $alternative['section']['start_time'], (float) $alternative['section']['end_time']];
                }
            }

            if (is_numeric($dispute['start_time'] ?? null) && is_numeric($dispute['end_time'] ?? null)) {
                $spans[] = [(float) $dispute['start_time'], (float) $dispute['end_time']];
            }

            foreach ($spans as [$start, $end]) {
                if (min($end, $talk->endTime) - max($start, $talk->startTime) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
