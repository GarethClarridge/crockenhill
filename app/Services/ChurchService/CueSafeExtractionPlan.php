<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;

/** Put output edges in measured word pauses; no-word windows retain whole-cue widening. */
class CueSafeExtractionPlan
{
    /** @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>} */
    public function forSection(ServiceSection $section): array
    {
        return $this->forSpans($section->processingLog, [['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time]]);
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>}
     */
    public function forSpans(MediaProcessingLog $log, array $spans): array
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $cues = $evidence->cues($log);
        $audit = [];
        foreach ($spans as $index => &$span) {
            foreach (['start' => 'start_time', 'end' => 'end_time'] as $edge => $key) {
                $original = $span[$key];
                $time = $original;
                $window = $evidence->window($cues, $original, $log->duration);
                if ($window !== null) {
                    $payload = $evidence->read($log, $window);
                    $words = $payload['words'];
                    if ($words !== []) {
                        $decision = $this->pause($words, $window, $original);
                        $span[$key] = $decision['time'];
                        $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original,
                            'time' => $decision['time'], 'seconds_added' => abs($decision['time'] - $original),
                            'seconds_moved' => $decision['time'] - $original, 'window' => $window,
                            'cue' => $window['cues'][0], 'cues' => $window['cues'],
                            ...$decision, 'reason' => 'word_pause',
                            'text_disagreement' => $this->disagrees($words, $window['cues'])];

                        continue;
                    }
                }
                $included = [];
                do {
                    $before = $time;
                    foreach ($cues as $cue) {
                        if ($cue['start'] < $time - 0.001 && $cue['end'] > $time + 0.001) {
                            $time = $edge === 'start' ? min($time, $cue['start']) : max($time, $cue['end']);
                            if (! in_array($cue, $included, true)) {
                                $included[] = $cue;
                            }
                        }
                    }
                } while ($before !== $time);
                $span[$key] = $time;
                if ($time !== $original || $window !== null) {
                    $included = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] < max($time, $original) && $cue['end'] > min($time, $original)));
                    $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original,
                        'time' => $time, 'seconds_added' => abs($time - $original), 'seconds_moved' => $time - $original,
                        'cue' => $included[0] ?? ($window['cues'][0] ?? null), 'cues' => $included,
                        'window' => $window, 'chosen_pause' => null, 'word_before' => null, 'word_after' => null,
                        'reason' => 'no_words_whole_cue', 'text_disagreement' => false];
                }
            }
        }
        unset($span);
        $merged = [];
        foreach ($spans as $span) {
            $last = count($merged) - 1;
            if ($last >= 0 && $span['start_time'] <= $merged[$last]['end_time']) {
                $merged[$last]['end_time'] = max($merged[$last]['end_time'], $span['end_time']);

                continue;
            }
            $merged[] = $span;
        }

        return ['segments' => $merged, 'cue_edge_widening' => $audit];
    }
    /** @param list<array{start: float, end: float, word: string}> $words
     * @param array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>} $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}
     */
    private function pause(array $words, array $window, float $original): array
    {
        usort($words, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $pauses = [];
        $left = null;
        $frontier = $window['start'];
        foreach ([...$words, null] as $right) {
            $end = $right['start'] ?? $window['end'];
            if ($end >= $frontier) {
                $pauses[] = ['start' => $frontier, 'end' => $end, 'length' => $end - $frontier, 'word_before' => $left, 'word_after' => $right];
            }
            if ($right !== null && $right['end'] >= $frontier) {
                $frontier = $right['end'];
                $left = $right;
            }
        }
        if ($pauses === []) {
            throw new \RuntimeException('edge_word_timings_invalid: no pause adjacent to edge cues');
        }
        usort($pauses, static function (array $a, array $b) use ($original): int {
            $size = $b['length'] <=> $a['length'];
            return $size ?: abs(max($a['start'], min($original, $a['end'])) - $original) <=> abs(max($b['start'], min($original, $b['end'])) - $original);
        });
        $pause = $pauses[0];

        return ['time' => max($pause['start'], min($original, $pause['end'])),
            'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
            'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
    }

    /** @param list<array{start: float, end: float, word: string}> $words
     * @param list<array{start: float, end: float, text: string}> $cues
     */
    private function disagrees(array $words, array $cues): bool
    {
        $normalize = static fn (string $text): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
        $inCue = array_filter($words, static function (array $word) use ($cues): bool {
            foreach ($cues as $cue) {
                if ($word['start'] < $cue['end'] && $word['end'] > $cue['start']) {
                    return true;
                }
            }
            return false;
        });

        return $normalize(implode(' ', array_column($inCue, 'word'))) !== $normalize(implode(' ', array_column($cues, 'text')));
    }

}
