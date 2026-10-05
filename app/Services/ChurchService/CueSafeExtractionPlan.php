<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;

/** Put output edges in measured word pauses; no-word windows retain whole-cue widening. */
class CueSafeExtractionPlan
{
    /** Seconds within which an output edge counts as sitting on a cue's boundary. */
    private const CUE_BOUNDARY_TOLERANCE = 0.05;

    /** Seconds from the cue boundary within which its edge word must be heard to anchor the cut. */
    private const ANCHOR_REACH = 2.0;

    /** Words of the cue's opening or closing run that must be heard in order to anchor the cut. */
    private const ANCHOR_RUN = 3;

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
                        $decision = $this->cueBoundaryPause($words, $window, $original, $edge) ?? $this->pause($words, $window, $original);
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

    /**
     * Held sections a final cut reaches into, other than the sections it is cutting. Holds are
     * checked against section bounds before edges widen into words and cues; a widened edge can
     * then carry held content into the output through a cue it shares with the held section.
     *
     * @param  list<array{start_time: float, end_time: float}>  $segments
     * @param  list<int>  $cutSectionIds
     * @return list<int>
     */
    public function heldSectionsCrossed(MediaProcessingLog $log, array $segments, array $cutSectionIds): array
    {
        return $log->serviceSections()->orderBy('start_time')->orderBy('id')->get()
            ->filter(static fn (ServiceSection $section): bool => ! in_array($section->id, $cutSectionIds, true)
                && HoldSectionForContentReview::isHeld($section->metadata->reviewFlags ?? [])
                && array_any($segments, static fn (array $segment): bool => $segment['start_time'] < (float) $section->end_time - 0.001
                    && $segment['end_time'] > (float) $section->start_time + 0.001))
            ->pluck('id')->values()->all();
    }

    /**
     * An edge on a cue boundary already decided the cue is in the output, so the cut goes in the
     * pause just outside that cue's own words: before its first word at a start, after its last
     * word at an end. The largest-pause rule cannot see this side: the window margin beyond the
     * cue can be the largest gap, and cutting there drops the whole cue (949's last verse), while
     * a word timed before its cue starts (964's "During") is lost to a smaller gap inside the
     * sentence. The cue's edge is found by its opening or closing run of words near the boundary,
     * so a repeated "the" nearer the boundary cannot stand in for it; when no run matches, or it
     * matches more than once, null hands the edge back to the largest pause.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}|null
     */
    private function cueBoundaryPause(array $words, array $window, float $original, string $edge): ?array
    {
        $isStart = $edge === 'start';
        // A cue touching the edge from the other side shares the boundary: its words may belong
        // to this output too (949's "thank you Mark"), so only the largest pause can place it.
        if (count($window['cues']) !== 1) {
            return null;
        }
        $cue = $window['cues'][0];
        if (abs(($isStart ? $cue['start'] : $cue['end']) - $original) > self::CUE_BOUNDARY_TOLERANCE) {
            return null;
        }
        $cueTokens = explode(' ', $this->normalize($cue['text']));
        if ($cueTokens === ['']) {
            return null;
        }
        $run = $isStart ? array_slice($cueTokens, 0, self::ANCHOR_RUN) : array_slice($cueTokens, -self::ANCHOR_RUN);
        usort($words, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $tokens = [];
        foreach ($words as $index => $word) {
            foreach (explode(' ', $this->normalize($word['word'])) as $token) {
                if ($token !== '') {
                    $tokens[] = ['token' => $token, 'word' => $index];
                }
            }
        }
        $anchors = [];
        for ($at = 0; $at + count($run) <= count($tokens); $at++) {
            if (array_column(array_slice($tokens, $at, count($run)), 'token') !== $run) {
                continue;
            }
            $index = $tokens[$isStart ? $at : $at + count($run) - 1]['word'];
            if (abs(($isStart ? $words[$index]['start'] : $words[$index]['end']) - $original) <= self::ANCHOR_REACH) {
                $anchors[$index] = true;
            }
        }
        if (count($anchors) !== 1) {
            return null;
        }
        $anchor = $words[array_key_first($anchors)];
        // The nearest gap no word is still sounding in: a word overlapping the anchor stays whole.
        $pauses = array_filter($this->pauses($words, $window), static fn (array $pause): bool => $isStart
            ? $pause['end'] <= $anchor['start'] : $pause['start'] >= $anchor['end']);
        if ($pauses === []) {
            return null;
        }
        $pause = $isStart ? $pauses[array_key_last($pauses)] : $pauses[array_key_first($pauses)];

        return ['time' => max($pause['start'], min($original, $pause['end'])),
            'chosen_pause' => ['start' => $pause['start'], 'end' => $pause['end']],
            'word_before' => $pause['word_before'], 'word_after' => $pause['word_after']];
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }

    /** @param list<array{start: float, end: float, word: string}> $words
     * @param  array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}  $window
     * @return array{time: float, chosen_pause: array{start: float, end: float}, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}
     */
    private function pause(array $words, array $window, float $original): array
    {
        $pauses = $this->pauses($words, $window);
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

    /**
     * Gaps in the window that no word is sounding in, in time order. The frontier is the latest
     * end of every word so far, so a gap after a short word inside a longer one is not a pause.
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float}  $window
     * @return list<array{start: float, end: float, length: float, word_before: array{start: float, end: float, word: string}|null, word_after: array{start: float, end: float, word: string}|null}>
     */
    private function pauses(array $words, array $window): array
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

        return $pauses;
    }

    /** @param list<array{start: float, end: float, word: string}> $words
     * @param  list<array{start: float, end: float, text: string}>  $cues
     */
    private function disagrees(array $words, array $cues): bool
    {
        $normalize = $this->normalize(...);
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
