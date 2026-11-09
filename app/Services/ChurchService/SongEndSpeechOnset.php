<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioTimeline;

/**
 * Where the speech after a song really starts, when the song fades into it (RA3, video plan
 * §4.1d, heard 11-07).
 *
 * A song that runs into speech is cut just before the first spoken word ({@see CueSafeExtractionPlan}
 * `song_end_speech`, `song_end_fade`), and its sound fades to the cut (E7). The edge decode
 * stretches that first word back over the outro's last note, so the fade took the note: all 10 such
 * ends heard on 11-07 were cut short. The aim is everything up to the first spoken word in the song
 * (operator, 11-07). So the cut is decoded in four overlapping single-chunk windows, and the song
 * ends {@see self::LEAD} s before the first run of words they agree on ({@see AgreedEdgeWords}).
 * On the A/B pairs the later ending was preferred or either in 11 of 13.
 *
 * Where the music has already stopped at the cut, the planner cut between two spoken sentences
 * and the song has nothing left to keep (1508, 175130: the current ending was preferred). The
 * classifier window just after the cut decides: music at or above {@see self::MUSIC} lets the cut move.
 */
class SongEndSpeechOnset
{
    /** v2 (11-08): decoded on the aligned server, words timed by their aligned ends. */
    public const RULE = 'song-end-slide-12-step-2-dtw-v2';

    public const WORD_TIMING = 'dtw';

    public const REASON = 'song_end_agreed_speech_onset';

    /** The windows' starts, from the cut: two-window agreement covers the cut − 6 s to + 6 s. */
    private const SLIDES = [-9.0, -7.0, -5.0, -3.0];

    /** One whisper chunk, well under its 30 s split. */
    private const SLIDE_SECONDS = 12.0;

    private const RUN_WORDS = 2;

    private const RUN_GAP = 1.5;

    /** A run may start this far before the cut: the cut's own pause holds the speech's first word. */
    private const BEFORE_CUT = 0.5;

    /** The song ends this far before the agreed speech. */
    public const LEAD = 0.3;

    /** Where the window just after the cut is read. */
    private const MUSIC_AT = 0.5;

    private const MUSIC = 0.3;

    public function __construct(private AgreedEdgeWords $agreement = new AgreedEdgeWords) {}

    /** @return array<string, array{start: float, end: float, window_rule: string, word_timing: string}> */
    public function windows(float $cut, ?float $duration): array
    {
        $limit = $duration ?? INF;
        $windows = [];

        foreach (self::SLIDES as $offset) {
            $start = max(0.0, $cut + $offset);
            $end = min($limit, $start + self::SLIDE_SECONDS);
            if ($end > $start) {
                $windows['cut-slide-'.(int) -$offset] = ['start' => $start, 'end' => $end, 'window_rule' => self::RULE, 'word_timing' => self::WORD_TIMING];
            }
        }

        return $windows;
    }

    /**
     * The song's later cut, from the banked decodes; null when the rule does not apply or a decode
     * is not banked, which leaves the planned cut.
     */
    public function cut(MediaProcessingLog $log, float $cut, ?AudioTimeline $timeline): ?float
    {
        $onset = $this->onset($log, $cut, $timeline);

        return $onset === null ? null : $onset - self::LEAD;
    }

    /**
     * Where the speech's first agreed word starts, from the banked decodes; null when the rule does
     * not apply or a decode is not banked.
     */
    public function onset(MediaProcessingLog $log, float $cut, ?AudioTimeline $timeline): ?float
    {
        if ($timeline === null) {
            return null;
        }

        $evidence = app(OutputEdgeWordTimings::class);
        $words = [];

        try {
            foreach ($this->windows($cut, $log->duration) as $name => $window) {
                $words[$name] = $evidence->read($log, $window)['tokens'];
            }
        } catch (OutputEdgeTimingsMissing) {
            return null;
        }

        return $this->onsetFrom($words, $cut, $timeline, $log->duration);
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string, aligned_end?: float}>>  $words  Each window's decoder tokens, by window name: unjoined, since joining punctuation stretches a word
     */
    public function cutFrom(array $words, float $cut, AudioTimeline $timeline, ?float $duration = 5000.0): ?float
    {
        $onset = $this->onsetFrom($words, $cut, $timeline, $duration);

        return $onset === null ? null : $onset - self::LEAD;
    }

    /**
     * Whether the music still plays just after the cut: where it has stopped, the planner cut between
     * two spoken sentences and the song has nothing left to keep (1508, 175130, 3598).
     */
    public function musicPlaysAfter(float $cut, AudioTimeline $timeline): bool
    {
        $window = $timeline->windowAt($cut + self::MUSIC_AT);

        return $window !== null && $timeline->windows[$window]['music'] >= self::MUSIC;
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string, aligned_end?: float}>>  $words  Each window's decoder tokens, by window name; aligned tokens are timed by their aligned ends
     */
    public function onsetFrom(array $words, float $cut, AudioTimeline $timeline, ?float $duration = 5000.0): ?float
    {
        if (! $this->musicPlaysAfter($cut, $timeline)) {
            return null;
        }

        $windows = $this->windows($cut, $duration);
        $timed = [];
        foreach ($windows as $name => $slide) {
            $tokens = $words[$name] ?? [];
            $timed[$name] = $tokens !== [] && array_all($tokens, static fn (array $token): bool => isset($token['aligned_end']))
                ? $this->agreement->aligned($tokens, $slide['start'])
                : $tokens;
        }
        $agreed = array_values(array_filter(
            $this->agreement->agreed($timed, $windows),
            static fn (array $word): bool => $word['start'] >= $cut - self::BEFORE_CUT,
        ));
        $run = array_find($this->agreement->runs($agreed, self::RUN_GAP), static fn (array $run): bool => count($run) >= self::RUN_WORDS);

        if ($run === null) {
            return null;
        }

        return $run[0]['start'] - self::LEAD > $cut ? $run[0]['start'] : null;
    }
}
