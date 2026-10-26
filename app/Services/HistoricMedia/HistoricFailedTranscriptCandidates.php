<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\ChurchServiceTranscript;
use App\Services\ChurchService\TranscriptCueEvidence;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\ServiceTranscriptPathologyDetector;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use App\Support\CanonicalJson;
use App\Support\TranscriptPromptEchoDetector;
use RuntimeException;

/**
 * Banked whole-service decodes are candidate evidence, never automatic truth.
 *
 * @phpstan-type Cue array{start: float, end: float, text: string}
 * @phpstan-type Window array{start: float, end: float, reason: string}
 * @phpstan-type Candidate array{id: string, window: Window, cue: Cue, flags: list<string>}
 * @phpstan-type Report array{policy: array<string, int>, windows: list<Window>, candidates: list<Candidate>, rejected: list<array{index: int, reason: string}>}
 */
class HistoricFailedTranscriptCandidates
{
    public const REASON = 'carried_from_h10b_redecode';

    public function __construct(
        private readonly ServiceTranscriptPathologyDetector $pathology,
        private readonly ServiceTranscriptRepetitionScreen $repetition,
        private readonly TranscriptPromptEchoDetector $echo,
    ) {}

    /** @param array<string, mixed> $donor
     * @return Report
     */
    public function inspect(ChurchServiceTranscript $current, array $donor, ?AudioTimeline $timeline = null): array
    {
        $cues = [];
        $rejected = [];
        $raw = $donor['cues'] ?? null;
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new RuntimeException('Donor cues must be a list.');
        }
        foreach ($raw as $index => $cue) {
            if (! is_array($cue) || ! is_numeric($cue['start'] ?? null) || ! is_numeric($cue['end'] ?? null)
                || ! is_finite((float) $cue['start']) || ! is_finite((float) $cue['end'])
                || (float) $cue['start'] < 0 || (float) $cue['end'] <= (float) $cue['start']
                || (float) $cue['end'] > $current->duration || ! is_string($cue['text'] ?? null) || trim($cue['text']) === '') {
                $rejected[] = ['index' => $index, 'reason' => 'invalid_cue'];

                continue;
            }
            $cues[] = ['start' => (float) $cue['start'], 'end' => (float) $cue['end'], 'text' => trim($cue['text'])];
        }
        usort($cues, static fn (array $a, array $b): int => [$a['start'], $a['end'], $a['text']] <=> [$b['start'], $b['end'], $b['text']]);
        $full = ChurchServiceTranscript::fromCues($cues, $current->duration, $current->source);
        $blind = $donor['unobservable_windows'] ?? [];
        if (! is_array($blind) || ! array_is_list($blind)) {
            throw new RuntimeException('Donor blind spans must be a list.');
        }
        foreach ($blind as $span) {
            if (! is_array($span) || ! is_numeric($span['start'] ?? null) || ! is_numeric($span['end'] ?? null)
                || ! is_finite((float) $span['start']) || ! is_finite((float) $span['end'])
                || (float) $span['start'] < 0 || (float) $span['end'] <= (float) $span['start']
                || (float) $span['end'] > $current->duration || ! is_string($span['reason'] ?? null) || trim($span['reason']) === '') {
                throw new RuntimeException('Invalid donor blind span; refusing to silently normalize it.');
            }
        }
        $blind = ChurchServiceTranscript::fromCues([], $current->duration, $current->source, $blind)->unobservableWindows;
        $bad = [...$this->pathology->detect($full), ...array_map(static fn ($block): array => $block->toArray(), $this->repetition->screen($full))];
        $windows = array_values(array_filter($current->unobservableWindows, static fn (array $w): bool => $w['reason'] === 'retranscription_failed'));
        $candidates = [];
        foreach ($windows as $window) {
            foreach ($full->cues as $cue) {
                if (! $this->overlaps($cue, $window)) {
                    continue;
                }
                $flags = [];
                if ($cue['start'] < $window['start'] || $cue['end'] > $window['end']) {
                    $flags[] = 'crosses_failed_window';
                }
                if (array_any($bad, fn (array $w): bool => $this->overlaps($cue, $w))) {
                    $flags[] = 'pathological';
                }
                if (array_any($blind, fn (array $w): bool => $this->overlaps($cue, $w))) {
                    $flags[] = 'donor_unobservable';
                }
                if ($this->echo->isPromptEcho($cue['text'])) {
                    $flags[] = 'prompt_echo';
                }
                if ($cue['end'] - $cue['start'] >= 20 && str_word_count($cue['text']) <= 6) {
                    $flags[] = 'long_low_information';
                }
                if (! TranscriptCueEvidence::hasWords($cue) || TranscriptCueEvidence::isTimingSuspect($cue, $timeline)) {
                    $flags[] = 'suspect_timing_or_no_words';
                }
                if (array_any($current->cues, fn (array $other): bool => $this->overlaps($cue, $other))) {
                    $flags[] = 'overlaps_existing_cue';
                }
                if (array_any($current->unobservableWindows, fn (array $other): bool => $other !== $window && $this->overlaps($cue, $other))) {
                    $flags[] = 'overlapping_windows';
                }
                if (count(array_filter($full->cues, fn (array $other): bool => $this->overlaps($cue, $other))) > 1) {
                    $flags[] = 'overlapping_donor_cues';
                }
                $id = CanonicalJson::hash($cue);
                $candidates[$id] = ['id' => $id, 'window' => $window, 'cue' => $cue, 'flags' => $flags];
            }
        }

        return ['policy' => ['long_cue_seconds' => 20, 'low_information_words' => 6], 'windows' => $windows,
            'candidates' => array_values($candidates), 'rejected' => $rejected];
    }

    /**
     * Split provenance from residual failure without claiming silent gaps hold words.
     *
     * @param  Report  $report
     * @param  list<string>  $accepted
     */
    public function materialize(ChurchServiceTranscript $current, array $report, array $accepted): ChurchServiceTranscript
    {
        $selected = [];
        foreach ($accepted as $id) {
            $matches = array_values(array_filter($report['candidates'], static fn (array $c): bool => $c['id'] === $id));
            if (count($matches) !== 1 || $matches[0]['flags'] !== [] || isset($selected[$id])) {
                throw new RuntimeException('Unknown, duplicate or quarantined candidate: '.$id);
            }
            $selected[$id] = $matches[0];
        }
        uasort($selected, static fn (array $a, array $b): int => $a['cue']['start'] <=> $b['cue']['start']);
        $windows = [];
        foreach ($current->unobservableWindows as $window) {
            $cursor = $window['start'];
            foreach ($selected as $candidate) {
                if ($candidate['window'] != $window) {
                    continue;
                }
                $cue = $candidate['cue'];
                if ($cue['start'] > $cursor) {
                    $windows[] = ['start' => $cursor, 'end' => $cue['start'], 'reason' => $window['reason']];
                }
                $windows[] = ['start' => $cue['start'], 'end' => $cue['end'], 'reason' => self::REASON];
                $cursor = $cue['end'];
            }
            if ($cursor < $window['end']) {
                $windows[] = ['start' => $cursor, 'end' => $window['end'], 'reason' => $window['reason']];
            }
        }
        $cues = [...$current->cues, ...array_column(array_values($selected), 'cue')];

        return ChurchServiceTranscript::fromCues($cues, $current->duration, $current->source, $windows);
    }

    /** @param array{start: float, end: float} $a
     * @param  array{start: float, end: float}  $b
     */
    private function overlaps(array $a, array $b): bool
    {
        return $a['start'] < $b['end'] && $a['end'] > $b['start'];
    }
}
