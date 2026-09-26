<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\DeadFeedInsideSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SoundStage;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\Media\Audio\ServiceTranscriptReader;

/**
 * Every action the music and silence rules would take over banked structure, for the §8 rule
 * replay and its precision gate (music and silence plan §8, §12 ruling 4).
 *
 * **Read-only, permanently.** It reports what the rules would do and writes nothing: R1 edge
 * moves, R2 and R3 move edges and replace sections, which only a detection run may do.
 *
 * Each rule's actions are isolated rather than inferred from notes:
 *  - **R1** ({@see DeadFeedInsideSection}) needs no timeline, so it runs alone over the banked
 *    structure; it neither inserts nor removes sections, so each output lines up with its input.
 *    A new `structure_song_over_dead_feed` flag on a song is an `r1_flag`, a moved song edge an
 *    `r1_edge`.
 *  - **R2 and R3** are the timeline's contribution, so the whole {@see SoundStage} runs twice,
 *    without the timeline and with it, and every section that differs is theirs. A song carrying
 *    `structure_song_widened_into_music` is an `r2_widening`; a song in place of an `other` with
 *    the same bounds is an `r3_proposal`; an `other` that shrank or went is an `r2_neighbour`.
 *    Anything else that differs is reported `unattributed` rather than dropped.
 *
 * The banked structure already passed through the stage when it was detected, as in the flag
 * recompute ({@see SoundStageFlagRecompute}); the paired runs cancel that out for R2 and R3.
 *
 * @phpstan-type RuleAction array{rule: string, type: string|null, title: string|null, before: array{0: float, 1: float}|null, after: array{0: float, 1: float}|null, flags: list<string>, notes: list<string>}
 * @phpstan-type ReplayAction array{run: int, year: string, rule: string, type: string|null, title: string|null, before: array{0: float, 1: float}|null, after: array{0: float, 1: float}|null, flags: list<string>, notes: list<string>}
 * @phpstan-type ReplayReport array{runs_assessed: int, unassessable: list<array{run: int, missing: list<string>}>, actions_by_rule: array<string, int>, runs_by_rule: array<string, int>, actions_by_year: array<string, array<string, int>>, actions: list<ReplayAction>}
 */
class MusicRuleReplay
{
    /** Bounds within this many seconds are the same bounds. */
    private const float SAME_BOUND_SECONDS = 0.05;

    public function __construct(
        private readonly BankedRunInputs $inputs,
        private readonly ServiceTranscriptReader $transcripts,
        private readonly SoundStage $soundStage,
        private readonly DeadFeedInsideSection $deadFeed,
    ) {}

    /**
     * @param  iterable<MediaProcessingLog>  $runs
     * @return ReplayReport
     */
    public function over(iterable $runs): array
    {
        $assessed = 0;
        $unassessable = [];
        $actions = [];

        foreach ($runs as $run) {
            $outcome = $this->forRun($run);

            if ($outcome['missing'] !== []) {
                $unassessable[] = ['run' => (int) $run->id, 'missing' => $outcome['missing']];

                continue;
            }

            $assessed++;
            array_push($actions, ...$outcome['actions']);
        }

        $byRule = [];
        $runsByRule = [];
        $byYear = [];

        foreach ($actions as $action) {
            $byRule[$action['rule']] = ($byRule[$action['rule']] ?? 0) + 1;
            $runsByRule[$action['rule']][$action['run']] = true;
            $byYear[$action['year']][$action['rule']] = ($byYear[$action['year']][$action['rule']] ?? 0) + 1;
        }

        ksort($byRule);
        ksort($byYear);

        return [
            'runs_assessed' => $assessed,
            'unassessable' => $unassessable,
            'actions_by_rule' => $byRule,
            'runs_by_rule' => array_map('count', $runsByRule),
            'actions_by_year' => $byYear,
            'actions' => $actions,
        ];
    }

    /**
     * @return array{actions: list<ReplayAction>, missing: list<string>}
     */
    public function forRun(MediaProcessingLog $run): array
    {
        return $this->inputs->within($run, function () use ($run): array {
            $structure = $this->inputs->structure($run);
            $rms = $this->inputs->rmsLog($run);
            $timeline = $this->inputs->audioTimeline($run);
            $transcript = $this->transcripts->tryRead($run);

            $missing = array_keys(array_filter([
                'service_structure' => $structure === null,
                'rms_log' => $rms === null,
                'audio_timeline' => $timeline === null,
                'transcript' => $transcript === null,
            ]));

            if ($structure === null || $rms === null || $timeline === null || $transcript === null) {
                return ['actions' => [], 'missing' => $missing];
            }

            $omitsSongs = ValidationContext::recordingOmitsSongs($run->processing_metadata);
            $identity = [
                'run' => (int) $run->id,
                'year' => $run->churchService?->date->format('Y') ?? 'unknown',
            ];

            $actions = [
                ...$this->deadFeedActions($structure, $this->deadFeed->apply($structure, $rms)),
                ...$this->timelineActions(
                    $this->soundStage->apply($structure, $rms, $transcript, $omitsSongs, null),
                    $this->soundStage->apply($structure, $rms, $transcript, $omitsSongs, $timeline),
                ),
            ];

            return [
                'actions' => array_map(static fn (array $action): array => [...$identity, ...$action], $actions),
                'missing' => [],
            ];
        });
    }

    /**
     * @return list<RuleAction>
     */
    private function deadFeedActions(ServiceStructure $banked, ServiceStructure $applied): array
    {
        $actions = [];

        foreach ($applied->sections as $index => $after) {
            $before = $banked->sections[$index];

            if ($before->type !== ServiceSectionType::Song) {
                continue;
            }

            $newNotes = array_values(array_diff($after->notes, $before->notes));

            if (! $this->sameBounds($before, $after)) {
                $actions[] = $this->action('r1_edge', $before, $after, $newNotes);
            }

            if (in_array(ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED, $after->reviewFlags, true)
                && ! in_array(ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED, $before->reviewFlags, true)) {
                $actions[] = $this->action('r1_flag', $before, $after, $newNotes);
            }
        }

        return $actions;
    }

    /**
     * @return list<RuleAction>
     */
    private function timelineActions(ServiceStructure $withoutTimeline, ServiceStructure $withTimeline): array
    {
        $unchanged = fn (ServiceStructureSection $section, ServiceStructure $other): bool => array_any(
            $other->sections,
            fn (ServiceStructureSection $candidate): bool => $candidate->type === $section->type && $this->sameBounds($candidate, $section),
        );

        $gone = array_values(array_filter($withoutTimeline->sections, fn (ServiceStructureSection $section): bool => ! $unchanged($section, $withTimeline)));
        $actions = [];
        $explained = [];

        foreach ($withTimeline->sections as $after) {
            if ($unchanged($after, $withoutTimeline)) {
                continue;
            }

            $before = $this->bestOverlap($after, $gone);

            if ($before !== null) {
                $explained[spl_object_id($gone[$before])] = true;
            }

            $original = $before === null ? null : $gone[$before];
            $rule = match (true) {
                in_array(ServiceStructureValidator::FLAG_SONG_WIDENED_INTO_MUSIC, $after->reviewFlags, true) => 'r2_widening',
                $after->type === ServiceSectionType::Song
                    && $original?->type === ServiceSectionType::Other
                    && $this->sameBounds($original, $after) => 'r3_proposal',
                $after->type === ServiceSectionType::Other && $original?->type === ServiceSectionType::Other => 'r2_neighbour',
                default => 'unattributed',
            };

            $actions[] = $this->action($rule, $original, $after, $original === null ? $after->notes : array_values(array_diff($after->notes, $original->notes)));
        }

        foreach ($gone as $section) {
            if (! isset($explained[spl_object_id($section)])) {
                $actions[] = $this->action($section->type === ServiceSectionType::Other ? 'r2_neighbour' : 'unattributed', $section, null, []);
            }
        }

        return $actions;
    }

    /**
     * @param  list<string>  $notes
     * @return RuleAction
     */
    private function action(string $rule, ?ServiceStructureSection $before, ?ServiceStructureSection $after, array $notes): array
    {
        return [
            'rule' => $rule,
            'type' => ($after ?? $before)?->type->value,
            'title' => ($after ?? $before)?->title,
            'before' => $before === null ? null : [$before->startTime, $before->endTime],
            'after' => $after === null ? null : [$after->startTime, $after->endTime],
            'flags' => $after === null ? [] : $after->reviewFlags,
            'notes' => $notes,
        ];
    }

    /**
     * The index of the section of the same type with the greatest overlap, else of any type (an
     * R3 song replaces an `other`), or null when none overlaps. Same type first, so a song that
     * widened further into an `other` than its own length still pairs with the song it was.
     *
     * @param  list<ServiceStructureSection>  $candidates
     */
    private function bestOverlap(ServiceStructureSection $section, array $candidates): ?int
    {
        $sameType = array_filter($candidates, static fn (ServiceStructureSection $candidate): bool => $candidate->type === $section->type);

        return $this->greatestOverlap($section, $sameType) ?? $this->greatestOverlap($section, $candidates);
    }

    /**
     * @param  array<int, ServiceStructureSection>  $candidates
     */
    private function greatestOverlap(ServiceStructureSection $section, array $candidates): ?int
    {
        $bestIndex = null;
        $bestOverlap = 0.0;

        foreach ($candidates as $index => $candidate) {
            $overlap = min($section->endTime, $candidate->endTime) - max($section->startTime, $candidate->startTime);

            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private function sameBounds(ServiceStructureSection $one, ServiceStructureSection $other): bool
    {
        return abs($one->startTime - $other->startTime) <= self::SAME_BOUND_SECONDS
            && abs($one->endTime - $other->endTime) <= self::SAME_BOUND_SECONDS;
    }
}
