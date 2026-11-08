<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

/**
 * The words overlapping decodes of one stretch of audio agree on (RA3, video plan §4.1d, 11-07).
 *
 * A single decode smears or stacks words near music: the first spoken words drift back over the
 * singing, words pile up at a window's edges, and whisper splits audio longer than 30 s at a join
 * where it drops words. A word counts where two windows heard the same text starting within
 * {@see self::TOLERANCE} s, each at least {@see self::EDGE} s inside its own window and no longer
 * than {@see self::WORD_SECONDS} s. A word smeared over sound it does not cover is left out.
 */
class AgreedEdgeWords
{
    /** A word near a window's edge is placed by the edge, not the audio. */
    private const EDGE = 1.0;

    private const TOLERANCE = 0.4;

    /** Longer than this, a decoded word is smeared over sound it does not cover. */
    private const WORD_SECONDS = 1.0;

    /**
     * @param  array<string, list<array{start: float, end: float, word: string}>>  $words  Each window's decoder tokens, by window name
     * @param  array<string, array{start: float, end: float}>  $windows  The windows to compare, by the same names
     * @return list<array{start: float, end: float}>
     */
    public function agreed(array $words, array $windows): array
    {
        $found = [];

        foreach ($windows as $name => $window) {
            foreach ($words[$name] ?? [] as $word) {
                $text = $this->normalised($word['word']);
                if ($text !== '' && $word['end'] - $word['start'] <= self::WORD_SECONDS
                    && $word['start'] >= $window['start'] + self::EDGE && $word['start'] <= $window['end'] - self::EDGE) {
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

            for ($next = $index + 1; $next < count($found) && $found[$next]['start'] - $word['start'] <= self::TOLERANCE; $next++) {
                if (! isset($used[$next]) && $found[$next]['text'] === $word['text'] && ! in_array($found[$next]['window'], array_column($group, 'window'), true)) {
                    $group[] = $found[$next];
                    $used[$next] = true;
                }
            }

            if (count($group) >= 2) {
                $agreed[] = ['start' => $this->median(array_column($group, 'start')), 'end' => $this->median(array_column($group, 'end'))];
            }
        }

        return $agreed;
    }

    /**
     * Agreed words split where the gap between them passes `$gap` seconds.
     *
     * @param  list<array{start: float, end: float}>  $agreed
     * @return list<list<array{start: float, end: float}>>
     */
    public function runs(array $agreed, float $gap): array
    {
        $runs = [];
        $run = [];

        foreach ($agreed as $word) {
            if ($run !== [] && $word['start'] - $run[array_key_last($run)]['end'] > $gap) {
                $runs[] = $run;
                $run = [];
            }
            $run[] = $word;
        }

        return $run === [] ? $runs : [...$runs, $run];
    }

    public function normalised(string $word): string
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
