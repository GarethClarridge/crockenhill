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
 * - Under the talking, the talking leaks into the instrument track as short spikes, so the outro
 *   is gone once half of a second is quiet (1966: heard +2.6 s, a strict second found +14.8 s).
 * - Taking the talking out leaves a trace of it, louder the louder the talking. The song ends once
 *   that trace would be heard through the fade, even if the outro plays on (3926, 3811, 2331).
 * - Ending at a silence, any voice left after the last sung word (a breath, a murmur) is taken out
 *   the same way (2858, 3586, 907).
 * - A sudden noise breaking into the dying outro (a breath into the microphone, a knock) lands in
 *   the instrument track, which taking the voice out cannot touch: the song ends before it (173622).
 * - Where the recording itself cuts out of the music to digital silence, the song fades into the
 *   cut over up to 3 s, from no earlier than the end of the last sung word (1314, 637). A dropout the music comes back from is not the end (176320).
 * - Where the outro never dies away within the separated stretch (music under a prayer), there is
 *   no ending to find, and the cut before the speech stands.
 *
 * Boundaries were read on 4451, 3891 and 828 and heard on 11-08 (three rounds); the thresholds
 * below reproduce those readings.
 */
class SongEndOutro
{
    public const SILENCE = 'silence';

    public const UNDER_SPEECH = 'under_speech';

    public const RECORDING_CUT = 'recording_cut';

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

    /** A sung phrase lasts at least this long... */
    private const SUNG_PHRASE_SECONDS = 0.5;

    /** ...and comes within this of the singing's level. */
    private const SUNG_PHRASE_BELOW = 10.0;

    /** The outro's level is read over this long before the singing stops. */
    private const OUTRO_REFERENCE = 3.0;

    /** The outro has died away this far below its level (828's fell 20 dB in the 2 s before the talking)... */
    private const OUTRO_GONE_BELOW = 20.0;

    /** ...for this long. */
    private const OUTRO_GONE_FOR = 1.0;

    /**
     * Once the talking has started, a second counts as quiet when this share of its frames are: the
     * talking leaks into the instrument track as short spikes, which kept 1966's outro "playing" 12 s
     * past its audible end (11-08).
     */
    private const OUTRO_GONE_SHARE_UNDER_SPEECH = 0.5;

    /** A noise breaks into the outro this far above its level (11-08). */
    private const NOISE_JUMP = 20.0;

    /** The talking taken out is heard above this, through the fade (11-08). */
    private const TALKING_HEARD_DB = -42.0;

    /** The fade starts no earlier than this before the speech (operator, 11-08). */
    private const LEAD_IN = 4.0;

    /** Enough fade to avoid a click at a silence. */
    private const CLICK = 0.1;

    /** The recording is muted, not quiet, at or below this (a stream cut to digital silence). */
    private const DIGITAL_SILENCE_DB = -75.0;

    /** Music cut off, not dying away: within 0.3 s the sound falls this far below its level over the second before... */
    private const RECORDING_CUT_DROP = 30.0;

    /** ...to this (17 of 107 silences in the corpus; a natural decay is 10–20 dB in a second). */
    private const RECORDING_CUT_REACHES_DB = -70.0;

    /**
     * ...and the cut is faded over up to this long, so the music does not stop dead (operator, 11-08:
     * 1314), but never from before the last sung word ends (operator, 11-08): 637's singing stops
     * 0.3 s before its cut, so it fades over 0.3 s.
     */
    private const RECORDING_CUT_FADE = 3.0;

    /** A muted stretch that the music comes back from this abruptly was a dropout, not the end (176320, 175132)... */
    private const DROPOUT_RETURN_RISE = 25.0;

    /** ...when it comes back above this for half a second. */
    private const DROPOUT_RETURN_DB = -60.0;

    /**
     * @param  array{start: float, frame_seconds: float, voice_db: list<float>, instruments_db: list<float>, instruments: array{disk: string, path: string}}  $stems
     * @param  float  $onset  Where the first agreed spoken word starts ({@see SongEndSpeechOnset::onset()})
     * @param  float  $cut  The cut the planner placed before the speech
     * @return array{time: float, fade_out: float, song_end_outro: string, speech: float, last_sung: float, outro_end: float, noise?: float, voice_removal?: array{from: float, instruments: array{disk: string, path: string}, instruments_start: float}}|null
     */
    public function ending(array $stems, float $onset, float $cut): ?array
    {
        ['speech' => $speech, 'last_sung' => $lastSung] = $this->voice($stems, $onset);
        $outroEnd = $this->outroEnd($stems, min($lastSung, $cut), $speech);
        if ($outroEnd === null) {
            return null;
        }
        $noise = $this->noiseAfterTheSinging($stems, $lastSung, $outroEnd);
        $found = ['speech' => $speech, 'last_sung' => $lastSung, 'outro_end' => $outroEnd];
        if ($noise !== null) {
            $found['noise'] = $noise;
            $outroEnd = $noise;
        }
        $removal = ['from' => $lastSung, 'instruments' => $stems['instruments'], 'instruments_start' => $stems['start']];
        $voiceLeft = $outroEnd < $speech && $this->voiceAfterTheSinging($stems, $lastSung, $outroEnd, $speech);
        if ($outroEnd < $speech && $this->recordingCutAt($stems, $outroEnd)) {
            $fadeFrom = max($outroEnd - self::RECORDING_CUT_FADE, min($lastSung, $outroEnd));
            $ending = ['time' => $outroEnd, 'fade_out' => round(max(self::CLICK, $outroEnd - $fadeFrom), 3), 'song_end_outro' => self::RECORDING_CUT, ...$found];

            return $voiceLeft ? [...$ending, 'voice_removal' => $removal] : $ending;
        }
        if ($outroEnd < $speech) {
            $ending = ['time' => $outroEnd, 'fade_out' => self::CLICK, 'song_end_outro' => self::SILENCE, ...$found];

            return $voiceLeft ? [...$ending, 'voice_removal' => $removal] : $ending;
        }
        $fadeFrom = max($lastSung, $speech - self::LEAD_IN);
        $end = $this->endBeforeTheTalkingShows($stems, $fadeFrom, $speech, $outroEnd);

        return ['time' => $end, 'fade_out' => round(max(self::CLICK, $end - $fadeFrom), 3), 'song_end_outro' => self::UNDER_SPEECH, ...$found,
            'voice_removal' => $removal];
    }

    /**
     * The latest end, no later than the outro's, at which the talking taken out stays inaudible: the
     * separation leaves a trace of the talking in the instrument track that scales with how loud the
     * talking is, so the fade must have turned it down far enough. Measured as the 90th percentile,
     * over the talking until the end, of the voice level plus the fade's gain (11-08: the six endings
     * heard as bad were −41 to −29 dB, all but one heard as fine −43 dB or quieter).
     *
     * @param  array{start: float, frame_seconds: float, voice_db: list<float>}  $stems
     */
    private function endBeforeTheTalkingShows(array $stems, float $fadeFrom, float $speech, float $outroEnd): float
    {
        $step = $stems['frame_seconds'];
        for ($end = $outroEnd; $end > $speech + 1e-6; $end = round($end - $step, 3)) {
            $heard = [];
            for ($time = $speech; $time < $end - 1e-6; $time = round($time + $step, 3)) {
                $db = $stems['voice_db'][(int) floor(($time - $stems['start']) / $step + 1e-6)] ?? null;
                if ($db !== null) {
                    $gain = max(1e-4, 1 - ($time - $fadeFrom) / max(1e-3, $end - $fadeFrom));
                    $heard[] = $db + 20 * log10($gain);
                }
            }
            if ($heard === []) {
                return $end;
            }
            sort($heard);
            if ($heard[(int) floor(0.9 * (count($heard) - 1))] <= self::TALKING_HEARD_DB) {
                return $end;
            }
        }

        return max($speech, $fadeFrom);
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

        return ['speech' => $time($speech), 'last_sung' => $time($this->lastSung($voice, $at($onset - 6.0), $speech, $step))];
    }

    /**
     * The frame after the last sung phrase before the talking at `$speech`. Voice is judged against the
     * singing's own level, over the whole separated stretch before the talking: against the talking's, quiet singing read as silence
     * (1966: singing at −50 dB under talking at −23). A sung phrase lasts {@see self::SUNG_PHRASE_SECONDS}
     * s and comes within {@see self::SUNG_PHRASE_BELOW} dB of that level, so a breath before the talking
     * is not one (173622). Voice running into the talking is the talking's start, unless no pause
     * separates it from the singing at all (4451): then the singing is taken to run into the talking.
     * With no sung phrase in the stretch, the singing stopped before it, at its start (2312).
     *
     * @param  list<float>  $voice
     */
    private function lastSung(array $voice, int $first, int $speech, float $step): int
    {
        $before = array_slice($voice, $first, max(0, $speech - $first));
        if ($before === []) {
            return $speech;
        }
        $sorted = array_slice($voice, 0, $speech);
        sort($sorted);
        $level = $sorted[(int) floor(0.9 * (count($sorted) - 1))];
        $active = static fn (float $db): bool => $db > $level - self::VOICE_QUIET_BELOW && $db > self::VOICE_QUIET_DB;
        $pause = (int) round(self::PAUSE_SECONDS / $step);
        $runs = [];
        foreach ($before as $offset => $db) {
            if (! $active($db)) {
                continue;
            }
            $index = $first + $offset;
            if ($runs !== [] && $index - $runs[array_key_last($runs)]['end'] < $pause) {
                $runs[array_key_last($runs)]['end'] = $index + 1;

                continue;
            }
            $runs[] = ['start' => $index, 'end' => $index + 1];
        }
        if ($runs !== [] && $speech - $runs[array_key_last($runs)]['end'] < $pause) {
            if (count($runs) === 1 && $runs[0]['start'] - $first < $pause) {
                return $speech;
            }
            array_pop($runs);
        }
        $sung = array_filter($runs, fn (array $run): bool => ($run['end'] - $run['start']) * $step >= self::SUNG_PHRASE_SECONDS - 0.001
            && array_reduce(array_slice($voice, $run['start'], $run['end'] - $run['start']), static fn (float $peak, float $db): float => max($peak, $db), -INF) >= $level - self::SUNG_PHRASE_BELOW);

        return $sung === [] ? $first : $sung[array_key_last($sung)]['end'];
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
        $at = fn (float $time): int => max(0, (int) floor(($time - $stems['start']) / $stems['frame_seconds'] + 1e-6));
        $before = array_slice($instruments, $at($from - self::OUTRO_REFERENCE), max(1, $at($from) - $at($from - self::OUTRO_REFERENCE)));
        if ($before === []) {
            return null;
        }
        $talking = $speech === null ? PHP_INT_MAX : $at($speech);
        $end = $this->quietFrom($instruments, $this->median($before), $at($from), $talking, $stems['frame_seconds']);
        while ($end !== null && ($return = $this->dropoutReturn($instruments, $end, $talking, $stems['frame_seconds'])) !== null) {
            $end = $this->quietFrom($instruments, $this->median(array_slice($instruments, $return, 5)), $return, $talking, $stems['frame_seconds']);
        }

        return $end === null ? null : round($stems['start'] + $end * $stems['frame_seconds'], 3);
    }

    /**
     * The first frame from `$first` where the instruments have died away below `$level`.
     *
     * @param  list<float>  $instruments
     */
    private function quietFrom(array $instruments, float $level, int $first, int $talking, float $step): ?int
    {
        $frames = (int) round(self::OUTRO_GONE_FOR / $step);
        $pause = (int) round(self::PAUSE_SECONDS / $step);
        for ($index = $first; $index + min($frames, $pause) <= count($instruments); $index++) {
            $beforeTalking = $index < $talking && $talking - $index >= $pause;
            $length = $beforeTalking ? min($frames, $talking - $index) : $frames;
            $span = array_slice($instruments, $index, $length);
            $quiet = count(array_filter($span, static fn (float $db): bool => $db <= $level - self::OUTRO_GONE_BELOW));
            $needed = $beforeTalking || $index < $talking ? $length : (int) ceil(self::OUTRO_GONE_SHARE_UNDER_SPEECH * $length - 1e-9);
            if (count($span) === $length && $quiet >= $needed) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Where the music comes back, before the talking, from a muted stretch starting at `$end`: the
     * stream dropped out and the outro resumed (176320). Null when the quiet was the end.
     *
     * @param  list<float>  $instruments
     */
    private function dropoutReturn(array $instruments, int $end, int $talking, float $step): ?int
    {
        $held = (int) round(0.5 / $step);
        if (($instruments[$end] ?? 0.0) > self::DIGITAL_SILENCE_DB) {
            return null;
        }
        for ($index = $end + 1; $index + $held <= min($talking, count($instruments)); $index++) {
            if ($instruments[$index - 1] <= self::DIGITAL_SILENCE_DB
                && $instruments[$index] - $instruments[$index - 1] >= self::DROPOUT_RETURN_RISE
                && array_all(array_slice($instruments, $index, $held), static fn (float $db): bool => $db > self::DROPOUT_RETURN_DB)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Where a sudden noise breaks into the dying outro, after the last sung word: the instrument track
     * jumps {@see self::NOISE_JUMP} dB above its level over the half second before. A breath into the
     * microphone or a knock lands there, not in the voice track, so taking the voice out leaves it
     * (173622, 11-08: "both have non speech breath sounds"; 3926, 2922). Music coming back from a
     * muted stretch is not a noise (176320). The song ends before it.
     *
     * @param  array{start: float, frame_seconds: float, instruments_db: list<float>}  $stems
     */
    private function noiseAfterTheSinging(array $stems, float $lastSung, float $outroEnd): ?float
    {
        $instruments = $stems['instruments_db'];
        $step = $stems['frame_seconds'];
        $at = static fn (float $time): int => max(0, (int) floor(($time - $stems['start']) / $step + 1e-6));
        $before = (int) round(0.5 / $step);
        for ($index = max($at($lastSung) + $before, $before); $index < min($at($outroEnd), count($instruments)); $index++) {
            $level = $this->median(array_slice($instruments, $index - $before, $before));
            if ($level > self::DIGITAL_SILENCE_DB && $instruments[$index] - $level >= self::NOISE_JUMP) {
                return round($stems['start'] + $index * $step, 3);
            }
        }

        return null;
    }

    /**
     * Whether voice sounds, at the singing's level, between the last sung word and an end before the
     * talking: a breath or a first spoken word that the song would otherwise keep (173622, 175597,
     * 11-08). It is taken out with the rest of the voice after the last sung word.
     *
     * @param  array{start: float, frame_seconds: float, voice_db: list<float>}  $stems
     */
    private function voiceAfterTheSinging(array $stems, float $lastSung, float $end, float $speech): bool
    {
        $at = static fn (float $time): int => max(0, (int) floor(($time - $stems['start']) / $stems['frame_seconds'] + 1e-6));
        $singing = array_slice($stems['voice_db'], 0, $at($speech));
        if ($singing === [] || $end <= $lastSung) {
            return false;
        }
        sort($singing);
        $level = $singing[(int) floor(0.9 * (count($singing) - 1))];

        return array_any(array_slice($stems['voice_db'], $at($lastSung), $at($end) - $at($lastSung)),
            static fn (float $db): bool => $db > $level - self::VOICE_QUIET_BELOW && $db > self::VOICE_QUIET_DB);
    }

    /**
     * Whether the recording itself cuts out at `$time`: within 0.3 s the
     * sound, voice and instruments together, reaches {@see self::RECORDING_CUT_REACHES_DB} dB, at least
     * {@see self::RECORDING_CUT_DROP} dB below its level over the second before.
     *
     * @param  array{start: float, frame_seconds: float, voice_db: list<float>, instruments_db: list<float>}  $stems
     */
    private function recordingCutAt(array $stems, float $time): bool
    {
        $index = (int) floor(($time - $stems['start']) / $stems['frame_seconds'] + 1e-6);
        $sound = static fn (int $frame): ?float => isset($stems['voice_db'][$frame], $stems['instruments_db'][$frame])
            ? 10 * log10(10 ** ($stems['voice_db'][$frame] / 10) + 10 ** ($stems['instruments_db'][$frame] / 10)) : null;
        $before = array_filter(array_map($sound, range(max(0, $index - 10), $index - 1)), static fn (?float $db): bool => $db !== null);
        $after = array_filter(array_map($sound, range($index, $index + 2)), static fn (?float $db): bool => $db !== null);
        if ($before === [] || $after === []) {
            return false;
        }

        return $this->median(array_values($before)) - min($after) >= self::RECORDING_CUT_DROP && min($after) <= self::RECORDING_CUT_REACHES_DB;
    }

    /** @param  list<float>  $levels */
    private function median(array $levels): float
    {
        sort($levels);

        return $levels[intdiv(count($levels), 2)];
    }
}
