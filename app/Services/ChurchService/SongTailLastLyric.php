<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\AudioTimeline;

/**
 * The last sung word a song's tail cut or its fade would take (WP16 P4, 11-13; E28).
 *
 * {@see SongTailSpeechOnset} cuts a fixed margin before the speech its decodes agree on and fades
 * the song over the 1.5 s before that, so a held final word can cross both: 1268 §4731's last
 * "praise" (decoded "grace") runs to 498.27 past a 497.41 cut faded from 495.91, and 1180 §2371's
 * "God of grace" to 304.30 past 303.36. The tail's banked decodes already hear such a word, but
 * decoded pace does not tell it from speech, so a word is protected only where the song's own
 * lyrics place it: its two words before match a phrase of the lyrics, and it is that phrase's next
 * word, spelt to within half the longer spelling's length (decoded "grace" for "praise"; a third
 * misses 1268). Two decodes from different windows must hear it, each in music, with the same
 * spelling within {@see self::AGREE} s at both ends, and neither may place it at its window's edge.
 * A word of the next line's transcript is speech, not lyric.
 *
 * The song then ends in the pause after that word, halfway to the next word either decode heard,
 * and its fade starts no earlier than the word's end. With no lyrics, no second decode or no word
 * after it, nothing changes: the rule never guesses an end. It adds no limit on how far the cut moves.
 *
 * Measured offline on the 13 November replay's 284 tail ends, with each song's lyrics found by its
 * title (WP16): 5 ends move and 2 fades shorten, none away from an end heard right. Here the lyrics
 * are those of the song the section is bound to ({@see ServiceSection::resolvedSongId()}).
 */
class SongTailLastLyric
{
    /** Decodes hear the same word where both its ends agree to within this. */
    private const AGREE = 0.4;

    /** A word this near its window's edge is placed by the edge. */
    private const EDGE = 0.25;

    private const MUSIC = 0.5;

    /**
     * @return array{time: float, fade_out: float, last_lyric: array{word: string, end: float, next_start: float}}|null
     */
    public function protect(MediaProcessingLog $log, float $end, float $cut, float $fadeOut, ?string $lyrics, ?string $nextLine, ?AudioTimeline $timeline, float $start): ?array
    {
        if ($lyrics === null || trim($lyrics) === '' || $timeline === null) {
            return null;
        }

        $evidence = app(OutputEdgeWordTimings::class);
        $windows = app(SongTailSpeechOnset::class)->windows($end, $log->duration);
        $words = [];

        try {
            foreach ($windows as $name => $window) {
                $words[$name] = $evidence->read($log, $window)['words'];
            }
        } catch (OutputEdgeTimingsMissing) {
            return null;
        }

        return $this->protectFrom($words, $windows, $cut, $fadeOut, $lyrics, $nextLine, $timeline, $start);
    }

    /**
     * @param  array<string, list<array{start: float, end: float, word: string}>>  $words  Each window's lexical words, by window name
     * @param  array<string, array{start: float, end: float}>  $windows
     * @return array{time: float, fade_out: float, last_lyric: array{word: string, end: float, next_start: float}}|null
     */
    public function protectFrom(array $words, array $windows, float $cut, float $fadeOut, string $lyrics, ?string $nextLine, AudioTimeline $timeline, float $start): ?array
    {
        $fadeFrom = $cut - $fadeOut;
        $phrases = $this->phrases($this->terms($lyrics));
        $spoken = array_flip($this->terms($nextLine ?? ''));
        $heard = [];

        foreach ($windows as $name => $window) {
            $lexical = $words[$name] ?? [];
            foreach ($lexical as $index => $word) {
                $text = $this->term($word['word']);
                if ($index < 2 || $text === '' || $text === 'music' || isset($spoken[$text])
                    || $word['end'] <= $word['start'] || $word['start'] < $start || $word['end'] <= $fadeFrom
                    || $word['start'] <= $window['start'] + self::EDGE || $word['end'] >= $window['end'] - self::EDGE
                    || ! $this->inMusic($timeline, $word['start'])
                    || ! $this->sung($phrases, $this->term($lexical[$index - 2]['word']), $this->term($lexical[$index - 1]['word']), $text)) {
                    continue;
                }
                $next = array_find(array_slice($lexical, $index + 1), static fn (array $after): bool => $after['start'] > $word['end']);
                $heard[] = ['window' => $name, 'text' => $text, 'start' => $word['start'], 'end' => $word['end'], 'next' => $next['start'] ?? null];
            }
        }

        $last = null;
        foreach ($heard as $index => $one) {
            foreach (array_slice($heard, $index + 1) as $other) {
                if ($other['window'] === $one['window'] || $other['text'] !== $one['text']
                    || abs($other['start'] - $one['start']) > self::AGREE || abs($other['end'] - $one['end']) > self::AGREE) {
                    continue;
                }
                $end = max($one['end'], $other['end']);
                $following = array_filter([$one['next'], $other['next']], static fn (?float $next): bool => $next !== null && $next > $end);
                if ($following !== [] && ($last === null || $end > $last['end'])) {
                    $last = ['word' => $one['text'], 'end' => $end, 'next_start' => min($following)];
                }
            }
        }

        if ($last === null) {
            return null;
        }
        $time = $cut < $last['end'] ? ($last['end'] + $last['next_start']) / 2 : $cut;
        $fade = round($time - max($fadeFrom, $last['end']), 3);
        if (abs($time - $cut) < 1e-6 && abs($fade - $fadeOut) < 1e-6) {
            return null;
        }

        return ['time' => round($time, 3), 'fade_out' => $fade, 'last_lyric' => $last];
    }

    /**
     * Each pair of consecutive lyric words, with the words that follow it.
     *
     * @param  list<string>  $terms
     * @return array<string, list<string>>
     */
    private function phrases(array $terms): array
    {
        $phrases = [];
        for ($index = 2; $index < count($terms); $index++) {
            $phrases[$terms[$index - 2].' '.$terms[$index - 1]][] = $terms[$index];
        }

        return $phrases;
    }

    /** @param  array<string, list<string>>  $phrases */
    private function sung(array $phrases, string $first, string $second, string $word): bool
    {
        return array_any($phrases[$first.' '.$second] ?? [], static fn (string $lyric): bool => levenshtein($lyric, $word) <= max(strlen($lyric), strlen($word)) / 2);
    }

    private function inMusic(AudioTimeline $timeline, float $time): bool
    {
        $window = $timeline->windowAt($time);

        return $window !== null && $timeline->windows[$window]['music'] >= self::MUSIC;
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        preg_match_all("/[a-z]+(?:'[a-z]+)?/i", $text, $matches);

        return array_values(array_filter(array_map($this->term(...), $matches[0]), static fn (string $term): bool => $term !== ''));
    }

    private function term(string $word): string
    {
        return (string) preg_replace('/[^a-z]/', '', mb_strtolower($word));
    }
}
