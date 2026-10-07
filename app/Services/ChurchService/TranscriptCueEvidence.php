<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\UntranscribedSpeechRecovery;

/**
 * Which transcript cues are evidence of speech where they say it is: the one definition recovery
 * coverage ({@see UntranscribedSpeechRecovery}), the no-line marker ({@see UntranscribedSpeechBeforeSection})
 * and song-end placement ({@see CueSafeExtractionPlan}) share (canary 12, B2).
 *
 * A cue of punctuation alone (". . . .") has no words: it is neither speech nor coverage. A cue
 * too long for its words is suspect: whisper filling its 30 s window with "Thank you." or "The
 * End" over a hymn's outro (1025, 1304, 1311), or a real benediction stretched back over the
 * singing (1028 §1400). Measured 2026-10-06 over 464 runs: every one of the 1,877 cues longer than
 * 15 s carried under a word a second. Length and density only raise the suspicion, since a slow
 * speaker or a reading with pauses looks the same (Codex review); the classifier must corroborate
 * it: music under part of the cue, no speech heard under it at all, or far more speech heard
 * than its words could fill. Without a timeline nothing is corroborated and the cue stands.
 */
final class TranscriptCueEvidence
{
    /** A real line is shorter: the floor {@see UntranscribedSpeechBeforeSection} measured. */
    public const LONGEST_LINE_SECONDS = 15.0;

    /** Fewer words than this per second leave most of a long cue without speech. */
    private const MINIMUM_WORDS_PER_SECOND = 1.0;

    /** A share of the cue in windows holding music that shows it runs over singing or an outro. */
    private const MUSIC_SHARE = 0.2;

    /**
     * Words per second of heard speech below which the cue cannot be what was said: a slow
     * speaker manages twice this, "Thank you." over 30 s of speech a fifteenth of it.
     */
    private const MINIMUM_WORDS_PER_SPEECH_SECOND = 0.3;

    /** @param array{start: float, end: float, text: string} $cue */
    public static function hasWords(array $cue): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $cue['text']) === 1;
    }

    /**
     * A cue with words whose span the evidence shows cannot be where they were said.
     *
     * @param  array{start: float, end: float, text: string}  $cue
     */
    public static function isTimingSuspect(array $cue, ?AudioTimeline $timeline): bool
    {
        $seconds = $cue['end'] - $cue['start'];
        $words = count(self::tokens($cue['text']));

        if (! self::hasWords($cue) || $seconds <= self::LONGEST_LINE_SECONDS || $words / $seconds >= self::MINIMUM_WORDS_PER_SECOND || $timeline === null) {
            return false;
        }

        $speechSeconds = $timeline->speechShare($cue['start'], $cue['end']) * $seconds;

        return $timeline->musicShare($cue['start'], $cue['end']) >= self::MUSIC_SHARE
            || $speechSeconds <= 0.0
            || $words / $speechSeconds < self::MINIMUM_WORDS_PER_SPEECH_SECOND;
    }

    /**
     * Speech where the cue says it is: coverage for recovery, a line for the no-line marker, and
     * a speech onset for a song's end.
     *
     * @param  array{start: float, end: float, text: string}  $cue
     */
    public static function isEvidence(array $cue, ?AudioTimeline $timeline): bool
    {
        return self::hasWords($cue) && ! self::isTimingSuspect($cue, $timeline);
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $normalized = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));

        return $normalized === '' ? [] : explode(' ', $normalized);
    }
}
