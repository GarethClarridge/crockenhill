<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;

/**
 * What the operator is shown about one run's ensemble: every open question and every
 * disagreement a three-to-one vote settled (the skim list), each with the versions on offer,
 * how each choice is answered, and the stretch of recording that decides it.
 *
 * It replays the latest bundle with the run's saved answers, so an answered question is not
 * offered again and the items match what {@see AnswerServiceStructureEnsembleQuestion} will
 * find open. It reads only; cutting the clips and answering are the commands' work.
 */
class EnsembleReviewExport
{
    /**
     * A passage this long or shorter is heard whole. Canary 9's split start/middle/end clips
     * left a ten-minute talk's end impossible to judge ("just some random clips").
     */
    public const WHOLE_PASSAGE_SECONDS = 900.0;

    /** Recording either side of a whole passage, so its edges are heard in context. */
    private const PADDING_SECONDS = 15.0;

    /** Recording either side of a long passage's disputed edges. */
    private const EDGE_CONTEXT_SECONDS = 60.0;

    private const LETTERS = 'ABCDEFGH';

    public function __construct(private readonly ServiceStructureEnsembleReplay $replay) {}

    /**
     * @return list<array{id: string, run: int, decided: bool, question_id: string, type: string, title: string, context: string, question: string, options: list<array{0: string, 1: string}>, answers: array<string, array{kind: string, slot: int|null}>, clips: list<array{start: float, end: float, label: string, cues: list<array{t: float, x: string}>}>}>
     */
    public function items(MediaProcessingLog $log): array
    {
        $bank = $log->processing_metadata?->raw['service_structure_ensemble'] ?? null;
        $evidence = is_array($bank) && $bank !== [] ? end($bank) : null;

        if (! is_array($evidence)) {
            return [];
        }

        $rulings = $log->processing_metadata?->raw['service_structure_ensemble_rulings'] ?? [];
        $replayed = $this->replay->replay($evidence, is_array($rulings) ? array_values(array_filter($rulings, 'is_array')) : [], $log);
        $cues = $this->replay->snapshot($evidence)['transcript']['cues'] ?? [];
        $cues = is_array($cues) ? array_values(array_filter($cues, 'is_array')) : [];
        $heading = $this->serviceHeading($log);
        $items = [];

        foreach (array_values($replayed['disputes']) as $index => $dispute) {
            $items[] = $this->item($log, $dispute, "{$log->id}-q{$index}", false, $cues, $heading);
        }

        foreach (array_values($replayed['majority_decisions'] ?? []) as $index => $decision) {
            $items[] = $this->item($log, $decision, "{$log->id}-m{$index}", true, $cues, $heading);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $dispute
     * @param  list<array<string, mixed>>  $cues
     * @return array{id: string, run: int, decided: bool, question_id: string, type: string, title: string, context: string, question: string, options: list<array{0: string, 1: string}>, answers: array<string, array{kind: string, slot: int|null}>, clips: list<array{start: float, end: float, label: string, cues: list<array{t: float, x: string}>}>}
     */
    private function item(MediaProcessingLog $log, array $dispute, string $id, bool $decided, array $cues, string $heading): array
    {
        $type = (string) ($dispute['type'] ?? 'unknown');
        $alternatives = array_values(array_filter($dispute['alternatives'] ?? [], 'is_array'));
        $spans = array_map(static fn (array $alternative): array => [
            (float) $alternative['section']['start_time'],
            (float) $alternative['section']['end_time'],
        ], $alternatives);

        if (is_numeric($dispute['start_time'] ?? null) && is_numeric($dispute['end_time'] ?? null)) {
            $spans[] = [(float) $dispute['start_time'], (float) $dispute['end_time']];
        }

        [$options, $answers] = $this->options($type, $dispute, $alternatives, $decided);
        $from = $spans === [] ? null : min(array_column($spans, 0));
        $to = $spans === [] ? null : max(array_column($spans, 1));

        return [
            'id' => $id,
            'run' => $log->id,
            'decided' => $decided,
            'question_id' => (string) $dispute['question_id'],
            'type' => $type,
            'title' => $from === null
                ? ucfirst($this->noun($type))." ({$heading})"
                : ucfirst($this->noun($type)).' at '.$this->clock($from).'–'.$this->clock((float) $to)." ({$heading})",
            'context' => $this->context($type, $dispute, $alternatives, $decided),
            'question' => $decided ? 'Is the majority right?' : 'Which is right?',
            'options' => $options,
            'answers' => $answers,
            'clips' => $spans === [] ? [] : $this->clips($spans, $cues),
        ];
    }

    /**
     * Each version on offer, then the answers that are not a version. A choice's answer kind is
     * fixed here so the apply command never has to interpret a label.
     *
     * @param  array<string, mixed>  $dispute
     * @param  list<array<string, mixed>>  $alternatives
     * @return array{0: list<array{0: string, 1: string}>, 1: array<string, array{kind: string, slot: int|null}>}
     */
    private function options(string $type, array $dispute, array $alternatives, bool $decided): array
    {
        $options = [];
        $answers = [];

        if ($type === 'degraded_coverage') {
            return [
                [['accept', 'Accept the structure from the drafts that completed'], ['defer', 'Can’t tell']],
                ['accept' => ['kind' => 'accept', 'slot' => null], 'defer' => ['kind' => 'defer', 'slot' => null]],
            ];
        }

        if ($type === 'sermon_absence') {
            return [
                [['absent', 'There was no sermon (say why in the note)'], ['defer', 'Can’t tell']],
                ['absent' => ['kind' => 'absent', 'slot' => null], 'defer' => ['kind' => 'defer', 'slot' => null]],
            ];
        }

        $total = $this->voterCount($dispute, $alternatives);
        $written = ($dispute['written'] ?? false) === true;
        $differences = $this->differences($alternatives);
        $supporting = $dispute['supporting_slots'] ?? [];

        foreach ($alternatives as $index => $alternative) {
            $section = $alternative['section'];
            $value = "alt{$index}";
            $label = sprintf(
                'Version %s (%d of %d drafts): %s “%s”, %s–%s',
                self::LETTERS[$index],
                count($alternative['slots']),
                $total,
                $this->noun((string) $section['type']),
                $section['title'] ?? $section['song_title'] ?? 'untitled',
                $this->clock((float) $section['start_time']),
                $this->clock((float) $section['end_time']),
            );

            foreach (['song_title' => 'song', 'reading_reference' => 'reading', 'sermon_reference' => 'preached passage'] as $field => $name) {
                if (in_array($field, $differences, true)) {
                    $label .= " · {$name}: ".($section[$field] ?? 'none');
                }
            }

            // The written group's winning version is the one its supporting drafts proposed.
            if ($supporting === $alternative['slots']) {
                $label .= match (true) {
                    $written && $decided => ' · settled by majority',
                    $written => ' · proposed now',
                    $decided => ' · outvoted',
                    default => '',
                };
            }

            $options[] = [$value, $label];
            $answers[$value] = ['kind' => 'choose', 'slot' => (int) $alternative['slots'][0]];
        }

        if (($dispute['absent_slots'] ?? []) !== []) {
            $absent = count($dispute['absent_slots']);
            $options[] = ['none', $written
                ? "No — there’s no {$this->noun($type)} here (remove it)"
                : "No — leave it out, as the {$absent} ".($absent === 1 ? 'draft' : 'drafts').' without it did'.($decided ? ' · settled by majority' : '')];
            $answers['none'] = ['kind' => $written ? 'remove' : 'accept', 'slot' => null];
        }

        if (count($alternatives) > 1 && $written) {
            $options[] = ['either', 'Either version is fine'];
            $answers['either'] = ['kind' => 'accept', 'slot' => null];
        }

        $options[] = ['neither', 'None of these is right (say what is in the note)'];
        $answers['neither'] = ['kind' => 'correct', 'slot' => null];
        $options[] = ['defer', 'Can’t tell'];
        $answers['defer'] = ['kind' => 'defer', 'slot' => null];

        return [$options, $answers];
    }

    /**
     * @param  array<string, mixed>  $dispute
     * @param  list<array<string, mixed>>  $alternatives
     */
    private function context(string $type, array $dispute, array $alternatives, bool $decided): string
    {
        if ($type === 'degraded_coverage') {
            return 'Fewer than four drafts completed, so the structure rests on fewer opinions than usual.';
        }

        if ($type === 'sermon_absence') {
            return 'No draft found a sermon, but not every draft said the service had none.';
        }

        if ($type === 'alignment') {
            return 'One draft’s section could belong to more than one of the others’ sections.';
        }

        $noun = $this->withArticle($this->noun($type));
        $total = $this->voterCount($dispute, $alternatives);
        $absent = count($dispute['absent_slots'] ?? []);
        $found = $total - $absent;
        $written = ($dispute['written'] ?? false) === true;
        $differences = $this->differences($alternatives);
        $what = $differences === [] ? 'details' : implode(', ', array_map(static fn (string $field): string => match ($field) {
            'song_title' => 'song',
            'oos_item_id' => 'order-of-service item',
            'reading_reference' => 'reading reference',
            'sermon_reference' => 'preached passage',
            default => $field,
        }, $differences));

        $sentence = match (true) {
            $absent > 0 && count($alternatives) <= 1 && $written => "{$found} of {$total} drafts found {$noun} here and {$absent} didn’t. It’s in the proposed structure.",
            $absent > 0 && count($alternatives) <= 1 => "Only {$found} of {$total} drafts found {$noun} here, so it’s not in the proposed structure.",
            $absent > 0 => "{$absent} of {$total} drafts found nothing here; the rest disagree on its {$what}.",
            default => "All {$total} drafts found {$noun} here but disagree on its {$what}.",
        };

        if ($decided) {
            $sentence .= ' Three of four drafts agreed, so this was settled without asking. Answer only if the majority is wrong.';
        }

        foreach ($alternatives as $index => $alternative) {
            $summary = $alternative['section']['summary'] ?? null;

            if (is_string($summary) && $summary !== '') {
                $sentence .= ' Version '.self::LETTERS[$index].": {$summary}";
            }
        }

        return $sentence;
    }

    /**
     * The fields the versions disagree on, in the composer's terms (free-text titles never are).
     *
     * @param  list<array<string, mixed>>  $alternatives
     * @return list<string>
     */
    private function differences(array $alternatives): array
    {
        if (count($alternatives) < 2) {
            return [];
        }

        $sections = array_column($alternatives, 'section');
        $differences = [];

        foreach (['start_time' => 'start', 'end_time' => 'end'] as $field => $name) {
            $values = array_map(static fn (array $section): float => (float) $section[$field], $sections);
            sort($values);

            if (end($values) - reset($values) >= 1.0) {
                $differences[] = $name;
            }
        }

        foreach (['type', 'song_title', 'oos_item_id', 'reading_reference', 'sermon_reference'] as $field) {
            if (count(array_unique(array_map(static fn (array $section): string => json_encode($section[$field] ?? null, JSON_THROW_ON_ERROR), $sections))) > 1) {
                $differences[] = $field;
            }
        }

        return $differences;
    }

    /**
     * One clip of the whole passage when it is short enough to hear, otherwise one around its
     * disputed start and one around its disputed end.
     *
     * @param  non-empty-list<array{0: float, 1: float}>  $spans
     * @param  list<array<string, mixed>>  $cues
     * @return list<array{start: float, end: float, label: string, cues: list<array{t: float, x: string}>}>
     */
    private function clips(array $spans, array $cues): array
    {
        $from = min(array_column($spans, 0));
        $to = max(array_column($spans, 1));

        if ($to - $from <= self::WHOLE_PASSAGE_SECONDS) {
            $windows = [[max(0.0, $from - self::PADDING_SECONDS), $to + self::PADDING_SECONDS, 'Whole passage']];
        } else {
            $starts = array_column($spans, 0);
            $ends = array_column($spans, 1);
            $windows = [
                [max(0.0, min($starts) - self::EDGE_CONTEXT_SECONDS), max($starts) + self::EDGE_CONTEXT_SECONDS, 'Around the start'],
                [max(0.0, min($ends) - self::EDGE_CONTEXT_SECONDS), max($ends) + self::EDGE_CONTEXT_SECONDS, 'Around the end'],
            ];
        }

        return array_map(fn (array $window): array => [
            'start' => round($window[0], 2),
            'end' => round($window[1], 2),
            'label' => $window[2],
            'cues' => $this->cuesBetween($cues, $window[0], $window[1]),
        ], $windows);
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     * @return list<array{t: float, x: string}>
     */
    private function cuesBetween(array $cues, float $from, float $to): array
    {
        $within = [];

        foreach ($cues as $cue) {
            $start = (float) ($cue['start'] ?? 0.0);
            $end = (float) ($cue['end'] ?? $start);

            if ($end > $from && $start < $to) {
                $within[] = ['t' => round($start, 2), 'x' => trim((string) ($cue['text'] ?? ''))];
            }
        }

        return $within;
    }

    /**
     * @param  array<string, mixed>  $dispute
     * @param  list<array<string, mixed>>  $alternatives
     */
    private function voterCount(array $dispute, array $alternatives): int
    {
        $slots = array_merge($dispute['absent_slots'] ?? [], ...array_column($alternatives, 'slots'));

        return max(1, count(array_unique($slots)));
    }

    private function serviceHeading(MediaProcessingLog $log): string
    {
        $service = $log->churchService;

        if ($service === null) {
            return "run {$log->id}";
        }

        return $service->date->toDateString().', '.mb_strtolower($service->service->label());
    }

    private function noun(string $type): string
    {
        $sectionType = ServiceSectionType::tryFrom($type);

        return match ($sectionType) {
            ServiceSectionType::BibleReading => 'Bible reading',
            ServiceSectionType::Other => 'other item',
            ServiceSectionType::Notices => 'notice',
            null => str_replace('_', ' ', $type),
            default => mb_strtolower($sectionType->label()),
        };
    }

    private function withArticle(string $noun): string
    {
        return (preg_match('/^[aeiou]/i', $noun) === 1 ? 'an ' : 'a ').$noun;
    }

    private function clock(float $seconds): string
    {
        $total = (int) round($seconds);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $rest = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }
}
