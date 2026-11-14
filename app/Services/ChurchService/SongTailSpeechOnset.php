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

    /** Two decodes hear the same word where their starts agree to within this ({@see AgreedEdgeWords}). */
    private const AGREE = 0.4;

    /** A line's first word can be decoded this much before its cue opens. */
    private const LINE_DRIFT = 1.0;

    /** A run must start before the song's recorded end; after it, the end rules place the cut. */
    private const BEFORE_END = 0.5;

    public function __construct(private AgreedEdgeWords $agreement = new AgreedEdgeWords) {}

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
    public function cut(MediaProcessingLog $log, float $end, float $planned, ?AudioTimeline $timeline, float $start = 0.0): ?float
    {
        return $this->locate($log, $end, $planned, $timeline, $start)['time'] ?? null;
    }

    /** @return array{time: float, speech_onset: float}|null */
    public function locate(MediaProcessingLog $log, float $end, float $planned, ?AudioTimeline $timeline, float $start = 0.0): ?array
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

        return $this->locateFrom($words, $end, $planned, $timeline, $log->duration, $start);
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string}>>  $words  Each window's decoder tokens, by window name: unjoined, since joining punctuation stretches a word
     */
    public function cutFrom(array $words, float $end, float $planned, AudioTimeline $timeline, ?float $duration = 5000.0, float $start = 0.0): ?float
    {
        return $this->locateFrom($words, $end, $planned, $timeline, $duration, $start)['time'] ?? null;
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string}>>  $words
     * @return array{time: float, speech_onset: float}|null
     */
    public function locateFrom(array $words, float $end, float $planned, AudioTimeline $timeline, ?float $duration = 5000.0, float $start = 0.0): ?array
    {
        $windows = $this->windows($end, $duration);
        $words = array_map(static fn (array $tokens): array => array_values(array_filter($tokens,
            static fn (array $word): bool => $word['start'] >= $start)), $words);
        $speech = $this->agreedOnset($words, $windows, $end, $timeline);
        $margin = self::AGREED_MARGIN;
        if ($speech === null && isset($windows['before'])) {
            $speech = $this->wideOnset($words['before'] ?? [], $windows['before'], $end, $timeline);
            $margin = self::WIDE_MARGIN;
        }
        if ($speech === null || $speech - $margin <= $start) {
            return null;
        }
        $cut = $speech - $margin;

        // Moved later only to keep singing: through speech alone the earlier cut is as right.
        $between = $this->between($timeline, $planned, $cut);
        if ($cut > $planned && $between !== [] && array_all($between, fn (array $window): bool => $this->isSpeechAlone($window))) {
            return null;
        }

        return ['time' => $cut, 'speech_onset' => $speech];
    }

    /**
     * The tail cut moved before the next spoken line it would split (WP19 R4, 11-13): the fixed margin
     * before the agreed words can land inside the line before them, as 1200 §2509's cut at 459.92 did
     * inside "Please remain standing." (from 458.44). Where the cut falls inside a worded transcript
     * line that is no lyric of the bound song (none of its three-word phrases, or for a shorter line
     * not all its words), and two of the tail's decodes, from different windows, hear that line's
     * first word before the cut within {@see self::AGREE} s of each other, each plainly timed and away
     * from its window's edges, the song ends {@see SongEndSpeechOnset::LEAD} before the earlier of
     * them. A decoded first word may open up to {@see self::LINE_DRIFT} s before its line's cue.
     *
     * With no lyrics a sung line cannot be told from a spoken one, so nothing changes; nor does it
     * with no second decode or a line already started before the cut. Nothing limits how far it moves.
     * Measured offline on the 13 November replay's 284 tail ends: 9 move, none away from a ruling.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array{time: float, line: string, first_word: float, from: float}|null
     */
    public function beforeSplitLine(MediaProcessingLog $log, float $end, float $cut, array $cues, ?string $lyrics, float $start): ?array
    {
        if ($lyrics === null || trim($lyrics) === '') {
            return null;
        }

        $evidence = app(OutputEdgeWordTimings::class);
        $windows = $this->windows($end, $log->duration);
        $words = [];

        try {
            foreach ($windows as $name => $window) {
                $words[$name] = $evidence->read($log, $window)['tokens'];
            }
        } catch (OutputEdgeTimingsMissing) {
            return null;
        }

        return $this->beforeSplitLineFrom($words, $windows, $cut, $cues, $lyrics, $start);
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string}>>  $words  Each window's decoder tokens, by window name
     * @param  array<string, array{start: float, end: float, window_rule?: string}>  $windows
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array{time: float, line: string, first_word: float, from: float}|null
     */
    public function beforeSplitLineFrom(array $words, array $windows, float $cut, array $cues, string $lyrics, float $start): ?array
    {
        $line = array_find($cues, fn (array $cue): bool => $cue['start'] < $cut - 0.001 && $cut + 0.001 < $cue['end'] && $this->terms($cue['text']) !== []);

        if ($line === null || $this->isLyric($this->terms($line['text']), $this->terms($lyrics))) {
            return null;
        }

        $first = $this->terms($line['text'])[0];
        $heard = [];
        foreach ($windows as $name => $window) {
            foreach ($words[$name] ?? [] as $word) {
                if (($this->terms($word['word'])[0] ?? null) === $first && $word['start'] >= $line['start'] - self::LINE_DRIFT && $word['start'] < $cut
                    && $word['end'] - $word['start'] <= self::WORD_SECONDS
                    && $word['start'] > $window['start'] + self::WIDE_EDGE && $word['start'] < $window['end'] - self::WIDE_EDGE) {
                    $heard[] = ['window' => $name, 'start' => $word['start']];
                }
            }
        }

        $agreed = array_filter($heard, static fn (array $one): bool => array_any($heard,
            static fn (array $other): bool => $other['window'] !== $one['window'] && abs($other['start'] - $one['start']) <= self::AGREE));

        if ($agreed === []) {
            return null;
        }

        $opening = min(array_column($agreed, 'start'));
        $time = $opening - SongEndSpeechOnset::LEAD;

        return $time > $start ? ['time' => round($time, 3), 'line' => $line['text'], 'first_word' => $opening, 'from' => $cut] : null;
    }

    /**
     * A line the song sings: one of its three-word phrases is the lyrics', or, shorter, all its words are.
     *
     * @param  list<string>  $line
     * @param  list<string>  $lyrics
     */
    private function isLyric(array $line, array $lyrics): bool
    {
        if (count($line) < 3) {
            return array_diff($line, $lyrics) === [];
        }

        $phrases = [];
        for ($index = 2; $index < count($lyrics); $index++) {
            $phrases[$lyrics[$index - 2].' '.$lyrics[$index - 1].' '.$lyrics[$index]] = true;
        }

        for ($index = 2; $index < count($line); $index++) {
            if (isset($phrases[$line[$index - 2].' '.$line[$index - 1].' '.$line[$index]])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        preg_match_all("/[a-z]+(?:'[a-z]+)?/", mb_strtolower($text), $matches);

        return array_values(array_filter(array_map(static fn (string $term): string => (string) preg_replace('/[^a-z]/', '', $term), $matches[0]),
            static fn (string $term): bool => $term !== ''));
    }

    /**
     * Whether moving a song's end from $from on to $to crosses a classifier window of speech
     * without music: the next item has already begun there, so its later agreed words are no reason
     * to move the end (WP16 P1, 11-13: 1007 §1261's "Praise be to the Lord" and 1314 §3989's "Over
     * the last few weeks" start before the words the decodes agree on).
     */
    public function crossesSpeech(AudioTimeline $timeline, float $from, float $to): bool
    {
        return $to > $from && array_any($this->between($timeline, $from, $to), fn (array $window): bool => $this->isSpeechAlone($window));
    }

    /** @return array<int, array{start: float, end: float, music: float, speech: float, singing: float}> */
    private function between(AudioTimeline $timeline, float $from, float $to): array
    {
        return array_filter($timeline->windows, static fn (array $window): bool => $window['end'] > $from && $window['start'] < $to);
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string}>>  $words
     * @param  array<string, array{start: float, end: float, window_rule: string}>  $windows
     */
    private function agreedOnset(array $words, array $windows, float $end, AudioTimeline $timeline): ?float
    {
        $slides = array_filter($windows, static fn (string $name): bool => str_starts_with($name, 'slide'), ARRAY_FILTER_USE_KEY);

        return $this->speechRunStart($this->agreement->runs($this->agreement->agreed($words, $slides), self::AGREED_RUN_GAP), $end, $timeline);
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
        $words = array_values(array_filter($words, fn (array $word): bool => $this->agreement->normalised($word['word']) !== ''));
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
}
