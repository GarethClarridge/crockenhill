<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioTimeline;

/**
 * Where speech starts inside a song section's tail (RA3 fix b, video plan §4.1d, measured 11-07).
 *
 * The transcript line carrying the speech opens 8–34 s before it is said, over the music, and a
 * single decode of the tail smears or stacks words there: the first words drift back over the
 * singing, words pile up at a window's edges, and whisper splits audio longer than 30 s at a join
 * where it drops words. So the tail is decoded in seven overlapping 12 s windows, and a word counts
 * where two windows agree on it. The song ends {@see self::AGREED_MARGIN} before the first agreed
 * words of the speech, since an utterance's opening words, nearest the music, rarely agree.
 *
 * Decoded pace does not tell singing from speech, so the classifier decides: the words must start
 * in a window of speech without music. Speech alone lets a sung line through where the window
 * hears both (1092 §2819's "O Church of Christ, rejoice", speech 0.54 and music 0.55).
 *
 * On the 41 listening rulings this leaves 5 ends wrong against the planned cut's 10, and it moves
 * none of 74 tails decoded as all sung.
 */
class SongTailSpeechOnset
{
    public const RULE = 'song-tail-slide-12-step-3-v1';

    public const REASON = 'song_end_tail_speech_onset';

    /** The sliding windows' starts, from the song's end. */
    private const SLIDES = [-22.0, -19.0, -16.0, -13.0, -10.0, -7.0, -4.0];

    private const SLIDE_SECONDS = 12.0;

    /** The wide decode, kept under whisper's 30 s split. */
    private const WIDE_FROM = -22.0;

    private const WIDE_TO = 6.0;

    /** A word near a window's edge is placed by the edge, not the audio. */
    private const AGREEMENT_EDGE = 1.0;

    private const AGREEMENT_TOLERANCE = 0.4;

    /** Longer than this, a decoded word is smeared over sound it does not cover. */
    private const WORD_SECONDS = 1.0;

    private const RUN_WORDS = 3;

    private const AGREED_RUN_GAP = 1.5;

    private const WIDE_EDGE = 0.25;

    private const WIDE_RUN_GAP = 1.0;

    /** How far back from a run's start its speech window is looked for. */
    private const GATE_SECONDS = 2.0;

    private const SPEECH = 0.5;

    private const MUSIC = 0.5;

    /** Runs this close are one stretch of speech. */
    private const JOIN_SECONDS = 3.0;

    /** An agreed run has lost its opening words: 136468's "And now to him" by 1.4 s. */
    private const AGREED_MARGIN = 1.5;

    private const WIDE_MARGIN = 0.5;

    /** A run must start before the song's recorded end; after it, the end rules place the cut. */
    private const BEFORE_END = 0.5;

    /** @return array<string, array{start: float, end: float, window_rule: string}> */
    public function windows(float $end, ?float $duration): array
    {
        $limit = $duration ?? INF;
        $windows = [];

        foreach (self::SLIDES as $offset) {
            $windows['slide-'.(int) -$offset] = [$end + $offset, $end + $offset + self::SLIDE_SECONDS];
        }
        $windows['before'] = [$end + self::WIDE_FROM, $end + self::WIDE_TO];

        $bounded = array_map(static fn (array $window): array => ['start' => max(0.0, $window[0]), 'end' => min($limit, $window[1]),
            'window_rule' => self::RULE], $windows);

        return array_filter($bounded, static fn (array $window): bool => $window['end'] > $window['start']);
    }

    /**
     * The song's cut where its tail holds speech, from the banked decodes; null when the rule does
     * not apply or a decode is not banked, which leaves the planned cut.
     */
    public function cut(MediaProcessingLog $log, float $end, float $planned, ?AudioTimeline $timeline): ?float
    {
        if ($timeline === null) {
            return null;
        }

        $evidence = app(OutputEdgeWordTimings::class);
        $words = [];

        try {
            foreach ($this->windows($end, $log->duration) as $name => $window) {
                $words[$name] = $evidence->read($log, $window)['tokens'];
            }
        } catch (OutputEdgeTimingsMissing) {
            return null;
        }

        return $this->cutFrom($words, $end, $planned, $timeline, $log->duration);
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string}>>  $words  Each window's decoder tokens, by window name: unjoined, since joining punctuation stretches a word
     */
    public function cutFrom(array $words, float $end, float $planned, AudioTimeline $timeline, ?float $duration = 5000.0): ?float
    {
        $windows = $this->windows($end, $duration);
        $agreed = $this->agreedOnset($words, $windows, $end, $timeline);
        $cut = $agreed !== null ? $agreed - self::AGREED_MARGIN : null;

        if ($cut === null && isset($windows['before'])) {
            $wide = $this->wideOnset($words['before'] ?? [], $windows['before'], $end, $timeline);
            $cut = $wide !== null ? $wide - self::WIDE_MARGIN : null;
        }

        if ($cut === null) {
            return null;
        }

        // Moved later only to keep singing: through speech alone the earlier cut is as right.
        $between = array_filter($timeline->windows, static fn (array $window): bool => $window['end'] > $planned && $window['start'] < $cut);
        if ($cut > $planned && $between !== [] && array_all($between, fn (array $window): bool => $this->isSpeechAlone($window))) {
            return null;
        }

        return $cut;
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string}>>  $words
     * @param  array<string, array{start: float, end: float, window_rule: string}>  $windows
     */
    private function agreedOnset(array $words, array $windows, float $end, AudioTimeline $timeline): ?float
    {
        $found = [];

        foreach ($windows as $name => $window) {
            if (! str_starts_with($name, 'slide')) {
                continue;
            }
            foreach ($words[$name] ?? [] as $word) {
                $text = $this->normalised($word['word']);
                if ($text !== '' && $word['end'] - $word['start'] <= self::WORD_SECONDS
                    && $word['start'] >= $window['start'] + self::AGREEMENT_EDGE && $word['start'] <= $window['end'] - self::AGREEMENT_EDGE) {
                    $found[] = ['start' => $word['start'], 'end' => $word['end'], 'text' => $text, 'window' => $name];
                }
            }
        }
        usort($found, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $agreed = [];
        $used = [];

        foreach ($found as $index => $word) {
            if (isset($used[$index])) {
                continue;
            }
            $group = [$word];
            $used[$index] = true;

            for ($next = $index + 1; $next < count($found) && $found[$next]['start'] - $word['start'] <= self::AGREEMENT_TOLERANCE; $next++) {
                if (! isset($used[$next]) && $found[$next]['text'] === $word['text'] && ! in_array($found[$next]['window'], array_column($group, 'window'), true)) {
                    $group[] = $found[$next];
                    $used[$next] = true;
                }
            }

            if (count($group) >= 2) {
                $agreed[] = ['start' => $this->median(array_column($group, 'start')), 'end' => $this->median(array_column($group, 'end'))];
            }
        }

        $runs = [];
        $run = [];

        foreach ($agreed as $word) {
            if ($run !== [] && $word['start'] - $run[array_key_last($run)]['end'] <= self::AGREED_RUN_GAP) {
                $run[] = $word;

                continue;
            }
            $runs[] = $run;
            $run = [$word];
        }
        $runs[] = $run;

        return $this->speechRunStart($runs, $end, $timeline);
    }

    /**
     * The wide decode's last run of plainly timed words in speech, taking in the words just before
     * it the classifier hears as speech (1297 §3795's spoken "Amen").
     *
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  array{start: float, end: float, window_rule: string}  $window
     */
    private function wideOnset(array $words, array $window, float $end, AudioTimeline $timeline): ?float
    {
        $words = array_values(array_filter($words, fn (array $word): bool => $this->normalised($word['word']) !== ''));
        usort($words, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $runs = [];
        $run = [];
        $previousEnd = -INF;

        foreach ($words as $word) {
            $plain = $word['end'] - $word['start'] <= self::WORD_SECONDS
                && $word['start'] - $window['start'] > self::WIDE_EDGE && $window['end'] - $word['start'] > self::WIDE_EDGE;

            if ($plain && $run !== [] && $word['start'] - $previousEnd <= self::WIDE_RUN_GAP) {
                $run[] = $word;
            } else {
                $runs[] = $run;
                $run = $plain ? [$word] : [];
            }
            // A smeared word breaks the run, and the gap after it is measured from its end.
            $previousEnd = $word['end'];
        }
        $runs[] = $run;

        $first = $this->speechRunStart($runs, $end, $timeline);

        if ($first === null) {
            return null;
        }

        $earlier = array_filter($words, static fn (array $word): bool => $word['start'] < $first - 0.001 && $word['start'] - $window['start'] > self::WIDE_EDGE);

        foreach (array_reverse($earlier) as $word) {
            $at = $timeline->windowAt($word['start']);
            if ($first - $word['end'] > self::JOIN_SECONDS || $at === null || $timeline->windows[$at]['speech'] < self::SPEECH) {
                break;
            }
            $first = $word['start'];
        }

        return $first;
    }

    /**
     * The start of the last run of three or more words opening, before the song's end, in a window
     * of speech without music, joined to the runs just before it.
     *
     * @param  list<list<array{start: float, end: float}>>  $runs
     */
    private function speechRunStart(array $runs, float $end, AudioTimeline $timeline): ?float
    {
        $speech = array_values(array_filter($runs, fn (array $run): bool => count($run) >= self::RUN_WORDS
            && $run[0]['start'] < $end + self::BEFORE_END && $this->opensInSpeech($timeline, $run[0]['start'])));

        if ($speech === []) {
            return null;
        }

        $best = array_pop($speech);

        foreach (array_reverse($speech) as $run) {
            if ($best[0]['start'] - $run[array_key_last($run)]['end'] > self::JOIN_SECONDS) {
                break;
            }
            $best = $run;
        }

        return $best[0]['start'];
    }

    private function opensInSpeech(AudioTimeline $timeline, float $start): bool
    {
        return array_any($timeline->windows, fn (array $window): bool => $window['end'] > $start && $window['start'] < $start + self::GATE_SECONDS
            && $this->isSpeechAlone($window));
    }

    /** @param array{speech: float, music: float} $window */
    private function isSpeechAlone(array $window): bool
    {
        return $window['speech'] >= self::SPEECH && $window['music'] < self::MUSIC;
    }

    private function normalised(string $word): string
    {
        return (string) preg_replace("/[^a-z0-9']/", '', mb_strtolower($word));
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
