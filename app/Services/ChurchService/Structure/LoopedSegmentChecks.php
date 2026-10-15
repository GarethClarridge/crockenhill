<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;

/**
 * Asks about a spoken passage whose opening is heard again at its end or just after it.
 *
 * A live service that played a pre-recorded segment sometimes let the video loop, so its first
 * seconds replay after it finishes and are cut off by whatever follows (1342's reading, 1341's,
 * 1363's reading and greeting: operator, 2026-10-14). A passage can also genuinely repeat its
 * opening words, as psalms often do, so the operator ruled this a flag to listen to, never a
 * trim: each repeat is an ordinary question whose one version is the section without the
 * repeat. "Looped" chooses that version; "it belongs here" accepts the section as composed. Its
 * answer goes through {@see ServiceStructureEnsembleRulingApplier} and carries to later
 * compositions by its scope, like any question.
 *
 * Only a repeat at the very end or start of a reading, talk or sermon is asked: those are
 * published as clips of their own, and a loop replays a few seconds before the service carries
 * on, so a repeat with the section's own speech continuing well beyond it is not this.
 */
final class LoopedSegmentChecks
{
    public const CHECK = 'looped_segment';

    /** Words in a row that must recur for a repeat to count. */
    private const PHRASE_WORDS = 8;

    /** Where a passage's opening is taken from: its first this-many seconds. */
    private const OPENING_SECONDS = 30.0;

    /** How far either side of a passage's end a loop is looked for. */
    private const SEARCH_SECONDS = 60.0;

    /** A repeat this close to a section's start or end sits at that edge. */
    private const EDGE_SECONDS = 5.0;

    /** Words that may differ in a row before the replay is taken to have stopped. */
    private const ALIGNMENT_SLACK = 4;

    private const SPOKEN_TYPES = [
        ServiceSectionType::Welcome,
        ServiceSectionType::Prayer,
        ServiceSectionType::Notices,
        ServiceSectionType::BibleReading,
        ServiceSectionType::ShortTalk,
        ServiceSectionType::Sermon,
        ServiceSectionType::Other,
    ];

    /** Sections published as clips of their own, where a replay would be heard. */
    private const ASKED_TYPES = [
        ServiceSectionType::BibleReading,
        ServiceSectionType::ShortTalk,
        ServiceSectionType::Sermon,
    ];

    /** @param  list<int>  $voters  The draws whose structures passed, which back the composed section */
    public function apply(EnsembleComposition $composition, ChurchServiceTranscript $transcript, array $voters): EnsembleComposition
    {
        if ($composition->refused) {
            return $composition;
        }

        $sections = $composition->structure->sections;
        usort($sections, static fn (ServiceStructureSection $a, ServiceStructureSection $b): int => $a->startTime <=> $b->startTime);
        $words = $this->words($transcript);
        $asked = [...$composition->disputes, ...$composition->majorityDecisions];
        $checks = [];
        $checked = [];

        foreach ($sections as $passage) {
            if (! in_array($passage->type, self::SPOKEN_TYPES, true)) {
                continue;
            }

            $loop = $this->loopAfter($passage, $words);

            if ($loop === null) {
                continue;
            }

            foreach ($sections as $section) {
                $version = $this->withoutLoop($section, $loop);

                if ($version === null || $this->alreadyAsked($section, $asked) || in_array([$section->startTime, $section->endTime], $checked, true)) {
                    continue;
                }

                $check = [
                    'type' => $section->type->value,
                    'check' => self::CHECK,
                    'loop' => ['start_time' => $loop['start'], 'end_time' => $loop['end'], 'opening_at' => $loop['opening_at']],
                    'start_time' => $section->startTime,
                    'end_time' => $section->endTime,
                    'written' => true,
                    'supporting_slots' => $voters,
                    'absent_slots' => [],
                    'alternatives' => [['slots' => $voters, 'section' => $version->withoutReviewFlags()->toArray()]],
                ];
                $check['question_id'] = hash('sha256', json_encode($check, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $checks[] = $check;
                $checked[] = [$section->startTime, $section->endTime];

                break;
            }
        }

        if ($checks === []) {
            return $composition;
        }

        $flagged = array_map(static fn (ServiceStructureSection $section): ServiceStructureSection => in_array([$section->startTime, $section->endTime], $checked, true)
            ? $section->withReviewFlags([ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES])
            : $section, $composition->structure->sections);
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

    /**
     * The first replay of a passage's opening near its end: where it starts, where the words stop
     * following the opening, and where the opening it replays was first heard.
     *
     * @param  list<array{0: string, 1: float, 2: float}>  $words
     * @return array{start: float, end: float, opening_at: float}|null
     */
    private function loopAfter(ServiceStructureSection $passage, array $words): ?array
    {
        $openings = [];

        foreach ($words as $index => $word) {
            if ($word[1] < $passage->startTime - 1.0) {
                continue;
            }

            if ($word[1] > $passage->startTime + self::OPENING_SECONDS) {
                break;
            }

            $phrase = $this->phrase($words, $index);

            if ($phrase !== null && count(array_unique(explode(' ', $phrase))) >= self::PHRASE_WORDS - 2) {
                $openings[$phrase] ??= $index;
            }
        }

        foreach ($words as $index => $word) {
            if ($word[1] < max($passage->endTime - self::SEARCH_SECONDS, $passage->startTime + self::OPENING_SECONDS + 15.0)) {
                continue;
            }

            if ($word[1] > $passage->endTime + self::SEARCH_SECONDS) {
                break;
            }

            $original = $openings[$this->phrase($words, $index) ?? ''] ?? null;

            if ($original === null) {
                continue;
            }

            $last = $index;
            $misses = 0;

            for ($offset = 0; $index + $offset < count($words) && $original + $offset < $index; $offset++) {
                if ($words[$index + $offset][0] === $words[$original + $offset][0]) {
                    $last = $index + $offset;
                    $misses = 0;
                } elseif (++$misses > self::ALIGNMENT_SLACK) {
                    break;
                }
            }

            return ['start' => $word[1], 'end' => $words[$last][2], 'opening_at' => $words[$original][1]];
        }

        return null;
    }

    /**
     * The section without a loop at its end or start, or null when the loop is not at an edge of
     * a section published on its own.
     *
     * @param  array{start: float, end: float, opening_at: float}  $loop
     */
    private function withoutLoop(ServiceStructureSection $section, array $loop): ?ServiceStructureSection
    {
        if (! in_array($section->type, self::ASKED_TYPES, true)) {
            return null;
        }

        $note = sprintf('Without the replay at %.1f–%.1fs (looped segment check).', $loop['start'], $loop['end']);

        if ($loop['start'] > $section->startTime + self::EDGE_SECONDS && $loop['start'] < $section->endTime
            && $section->endTime - $loop['end'] <= self::EDGE_SECONDS) {
            return $section->withTimes($section->startTime, $loop['start'], [$note]);
        }

        // A replay ending before the section starts is already outside it (1363's greeting, set apart by an answer).
        if (abs($loop['start'] - $section->startTime) <= self::EDGE_SECONDS && $loop['end'] > $section->startTime + 1.0
            && $section->endTime - $loop['end'] > self::EDGE_SECONDS) {
            return $section->withTimes($loop['end'], $section->endTime, [$note]);
        }

        return null;
    }

    /**
     * A section another question already asks about keeps that one question (one question per clip).
     *
     * @param  list<array<string, mixed>>  $asked
     */
    private function alreadyAsked(ServiceStructureSection $section, array $asked): bool
    {
        foreach ($asked as $dispute) {
            if (ServiceStructureEnsembleRulingApplier::sectionFor([$section], $dispute) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{0: string, 1: float, 2: float}> Each word with its cue's start and end */
    private function words(ChurchServiceTranscript $transcript): array
    {
        $words = [];

        foreach ($transcript->cues as $cue) {
            foreach (preg_split('/\s+/', strtolower((string) preg_replace('/[^a-z0-9\s]/i', ' ', $cue['text'])), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $words[] = [$word, (float) $cue['start'], (float) $cue['end']];
            }
        }

        return $words;
    }

    /** @param  list<array{0: string, 1: float, 2: float}>  $words */
    private function phrase(array $words, int $index): ?string
    {
        $slice = array_slice($words, $index, self::PHRASE_WORDS);

        return count($slice) === self::PHRASE_WORDS ? implode(' ', array_column($slice, 0)) : null;
    }
}
