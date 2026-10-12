<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

/**
 * An edge window's decoded words, with any word timed backwards or before the window refitted
 * between its neighbours. whisper.cpp now and then emits one (run 1010's edge job failed outright
 * on it); dropping it would open a pause where it was said, and a cut could fall there, so it
 * keeps its place and fills the gap the words around it leave.
 */
final class EdgeWindowWords
{
    /**
     * @param  list<array{start: float, end: float, word: string}>  $words  In decode order
     * @return list<array{start: float, end: float, word: string}>
     */
    public static function refitted(array $words): array
    {
        $isMalformed = static fn (array $word): bool => ! is_finite($word['start']) || ! is_finite($word['end'])
            || $word['start'] < 0 || $word['end'] < $word['start'];

        foreach ($words as $index => $word) {
            if (! $isMalformed($word)) {
                continue;
            }

            $from = $index > 0 ? $words[$index - 1]['end'] : 0.0;
            $next = array_values(array_filter(array_slice($words, $index + 1), static fn (array $later): bool => ! $isMalformed($later)))[0] ?? null;
            $to = max($from, $next['start'] ?? $from);
            $words[$index] = ['start' => $from, 'end' => $to, 'word' => $word['word']];
        }

        return $words;
    }
}
