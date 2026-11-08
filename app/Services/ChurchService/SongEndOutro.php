<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

/**
 * How a song that runs into speech ends, from its separated voice and instruments (RA3e, video plan
 * §4.1d, E29; operator 11-08):
 *
 * - The outro dies away before anyone speaks: the song ends at that silence, with a fade only
 *   long enough to avoid a click (828).
 * - The talking starts over the outro: the song plays on until the outro has died away. From the
 *   last sung word the voice track is replaced by the instruments, so the talking is taken out,
 *   and the sound fades from the last sung word to the outro's end; if that word is more than
 *   {@see self::LEAD_IN} s before the speech, from {@see self::LEAD_IN} s before it (3891).
 * - Where the talking follows the singing at once there is no pause to place the last sung word:
 *   it is taken to be the speech's start, an accepted imperfection (4451).
 * - Where the outro never dies away within the separated stretch (music under a prayer), there is
 *   no ending to find, and the cut before the speech stands.
 *
 * Boundaries were read on 4451, 3891 and 828 and heard on 11-08; the thresholds below reproduce
 * those readings.
 */
class SongEndOutro
{
    public const SILENCE = 'silence';

    public const UNDER_SPEECH = 'under_speech';

    /** A voice frame this far below the singing's level is quiet. */
    private const VOICE_QUIET_BELOW = 20.0;

    /** And always below this. */
    private const VOICE_QUIET_DB = -60.0;

    /** A pause between singing and talking lasts at least this long. */
    private const PAUSE_SECONDS = 0.3;

    /** The voice track times the talking within this of the agreed onset. */
    private const PAUSE_TO_SPEECH = 1.0;

    /** Voice this long, not a click or a breath (828's 0.1 s blip before the talking). */
    private const VOICED_SECONDS = 0.2;

    /** The outro's level is read over this long before the singing stops. */
    private const OUTRO_REFERENCE = 3.0;

    /** The outro has died away this far below its level (828's fell 20 dB in the 2 s before the talking)... */
    private const OUTRO_GONE_BELOW = 20.0;

    /** ...for this long. */
    private const OUTRO_GONE_FOR = 1.0;

    /** The fade starts no earlier than this before the speech (operator, 11-08). */
    private const LEAD_IN = 4.0;

    /** Enough fade to avoid a click at a silence. */
    private const CLICK = 0.1;

    /**
     * @param  array{start: float, frame_seconds: float, voice_db: list<float>, instruments_db: list<float>, instruments: array{disk: string, path: string}}  $stems
     * @param  float  $onset  Where the first agreed spoken word starts ({@see SongEndSpeechOnset::onset()})
     * @param  float  $cut  The cut the planner placed before the speech
     * @return array{time: float, fade_out: float, song_end_outro: string, speech: float, last_sung: float, outro_end: float, voice_removal?: array{from: float, instruments: array{disk: string, path: string}, instruments_start: float}}|null
     */
    public function ending(array $stems, float $onset, float $cut): ?array
    {
        ['speech' => $speech, 'last_sung' => $lastSung] = $this->voice($stems, $onset);
        $outroEnd = $this->outroEnd($stems, min($lastSung, $cut), $speech);
        if ($outroEnd === null) {
            return null;
        }
        $found = ['speech' => $speech, 'last_sung' => $lastSung, 'outro_end' => $outroEnd];
        if ($outroEnd <= $speech) {
            return ['time' => $outroEnd, 'fade_out' => self::CLICK, 'song_end_outro' => self::SILENCE, ...$found];
        }
        $fadeFrom = max($lastSung, $speech - self::LEAD_IN);

        return ['time' => $outroEnd, 'fade_out' => round($outroEnd - $fadeFrom, 3), 'song_end_outro' => self::UNDER_SPEECH, ...$found,
            'voice_removal' => ['from' => $lastSung, 'instruments' => $stems['instruments'], 'instruments_start' => $stems['start']]];
    }

    /**
     * Where the talking starts and the singing stopped, on the voice track. The talking starts where
     * the voice comes back within {@see self::PAUSE_TO_SPEECH} s of the agreed onset (the aligned words
     * place it, the voice track times it: 828's agreed onset was 0.7 s early). The singing stopped where
     * the quiet before it began; a quiet shorter than {@see self::PAUSE_SECONDS} s is no pause, and the
     * singing is taken to run into the talking (4451).
     *
     * @param  array{start: float, frame_seconds: float, voice_db: list<float>}  $stems
     * @return array{speech: float, last_sung: float}
     */
    public function voice(array $stems, float $onset): array
    {
        $voice = $stems['voice_db'];
        $step = $stems['frame_seconds'];
        $at = static fn (float $time): int => max(0, min(count($voice), (int) floor(($time - $stems['start']) / $step)));
        $time = static fn (int $index): float => round($stems['start'] + $index * $step, 3);
        $heard = array_slice($voice, $at($onset - 6.0), $at($onset + 2.0) - $at($onset - 6.0));
        if ($heard === []) {
            return ['speech' => $onset, 'last_sung' => $onset];
        }
        sort($heard);
        $level = $heard[(int) floor(0.9 * (count($heard) - 1))];
        $active = static fn (float $db): bool => $db > $level - self::VOICE_QUIET_BELOW && $db > self::VOICE_QUIET_DB;
        $speech = null;
        $voiced = (int) round(self::VOICED_SECONDS / $step);
        for ($index = $at($onset - self::PAUSE_TO_SPEECH); $index < $at($onset + self::PAUSE_TO_SPEECH); $index++) {
            if (($index === 0 || ! $active($voice[$index - 1]))
                && array_all(array_slice($voice, $index, $voiced), $active) && count(array_slice($voice, $index, $voiced)) === $voiced) {
                $speech = $index;
                break;
            }
        }
        if ($speech === null) {
            return ['speech' => $onset, 'last_sung' => $onset];
        }
        // Walk back over the quiet, and over blips too short to be voice.
        $sounding = static fn (int $index): bool => array_any(range(max(0, $index - $voiced + 1), $index),
            static fn (int $first): bool => count(array_slice($voice, $first, $voiced)) === $voiced && array_all(array_slice($voice, $first, $voiced), $active));
        $quiet = $speech;
        while ($quiet > $at($onset - 6.0) && ! $sounding($quiet - 1)) {
            $quiet--;
        }
        $pause = ($speech - $quiet) * $step >= self::PAUSE_SECONDS - 0.001;

        return ['speech' => $time($speech), 'last_sung' => $time($pause ? $quiet : $speech)];
    }

    /**
     * Where the instruments have died away, after `$from`: the first frame from which they stay
     * {@see self::OUTRO_GONE_BELOW} dB below their level before `$from` for {@see self::OUTRO_GONE_FOR} s,
     * or, before the talking, until it starts: the talking leaks into the instrument track (828).
     *
     * @param  array{start: float, frame_seconds: float, instruments_db: list<float>}  $stems
     */
    public function outroEnd(array $stems, float $from, ?float $speech = null): ?float
    {
        $instruments = $stems['instruments_db'];
        $at = fn (float $time): int => max(0, (int) floor(($time - $stems['start']) / $stems['frame_seconds']));
        $before = array_slice($instruments, $at($from - self::OUTRO_REFERENCE), max(1, $at($from) - $at($from - self::OUTRO_REFERENCE)));
        if ($before === []) {
            return null;
        }
        sort($before);
        $level = $before[intdiv(count($before), 2)];
        $frames = (int) round(self::OUTRO_GONE_FOR / $stems['frame_seconds']);
        $talking = $speech === null ? PHP_INT_MAX : $at($speech);
        $pause = (int) round(self::PAUSE_SECONDS / $stems['frame_seconds']);
        for ($index = $at($from); $index + min($frames, $pause) <= count($instruments); $index++) {
            $length = $index < $talking && $talking - $index >= $pause ? min($frames, $talking - $index) : $frames;
            $span = array_slice($instruments, $index, $length);
            if (count($span) === $length && array_all($span, static fn (float $db): bool => $db <= $level - self::OUTRO_GONE_BELOW)) {
                return round($stems['start'] + $index * $stems['frame_seconds'], 3);
            }
        }

        return null;
    }
}
