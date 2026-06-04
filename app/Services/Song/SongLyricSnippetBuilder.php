<?php

declare(strict_types=1);

namespace App\Services\Song;

use Illuminate\Support\Collection;

class SongLyricSnippetBuilder
{
    private const MAX_SNIPPETS = 5;

    /**
     * Determine whether a lyric search match exists in the given lyrics text
     * for the provided tokens.
     *
     * @param  Collection<int, non-empty-string>  $tokens
     */
    public function hasLyricMatch(string $lyricsPlain, Collection $tokens): bool
    {
        if ($tokens->isEmpty() || $lyricsPlain === '') {
            return false;
        }

        $lower = mb_strtolower($lyricsPlain);

        foreach ($tokens as $token) {
            if (! str_contains($lower, mb_strtolower($token))) {
                return false;
            }
        }

        // At least one token must appear *only* in the lyrics — i.e. this is a lyric
        // match not fully explained by a title/author/CCLI hit. In practice the
        // component decides whether to show snippets by also checking title/author, so
        // here we simply confirm at least one token is present in lyrics.
        return true;
    }

    /**
     * Extract up to MAX_SNIPPETS unique matching lines from lyrics_plain, with
     * matched tokens highlighted using <mark> tags.
     *
     * Lines are split on newlines. Blank lines (verse separators) are skipped.
     * Duplicate lines (case-insensitive) are deduplicated.
     *
     * @param  Collection<int, non-empty-string>  $tokens
     * @return list<string> HTML-safe strings with <mark> highlights
     */
    public function buildSnippets(string $lyricsPlain, Collection $tokens): array
    {
        if ($tokens->isEmpty() || $lyricsPlain === '') {
            return [];
        }

        $seen = [];
        $snippets = [];

        foreach (explode("\n", $lyricsPlain) as $rawLine) {
            if (count($snippets) >= self::MAX_SNIPPETS) {
                break;
            }

            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            $lower = mb_strtolower($line);

            // Only include lines that contain at least one token.
            $hasMatch = false;
            foreach ($tokens as $token) {
                if (str_contains($lower, mb_strtolower($token))) {
                    $hasMatch = true;
                    break;
                }
            }

            if (! $hasMatch) {
                continue;
            }

            // Deduplicate case-insensitively.
            if (isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            $snippets[] = $this->highlight($line, $tokens);
        }

        return $snippets;
    }

    /**
     * HTML-escape a line then wrap each matched token in <mark>.
     *
     * Collects all match positions across all tokens in a single scan, merges
     * overlapping ranges, then builds the output in one pass — ensuring no
     * nested or broken <mark> tags are ever produced.
     *
     * @param  Collection<int, non-empty-string>  $tokens
     */
    private function highlight(string $line, Collection $tokens): string
    {
        $escaped = e($line);

        // Collect [start, end) byte ranges for all token matches.
        /** @var list<array{int, int}> $ranges */
        $ranges = [];

        foreach ($tokens as $token) {
            $escapedToken = e($token);
            if ($escapedToken === '') {
                continue;
            }
            $pattern = '/'.preg_quote($escapedToken, '/').'/iu';
            preg_match_all($pattern, $escaped, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$match, $offset]) {
                $ranges[] = [$offset, $offset + strlen($match)];
            }
        }

        if ($ranges === []) {
            return $escaped;
        }

        // Sort by start position, then merge overlapping/adjacent ranges.
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        /** @var list<array{int, int}> $merged */
        $merged = [];

        foreach ($ranges as [$start, $end]) {
            if ($merged !== [] && $start <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        // Build the output string with mark tags inserted around merged ranges.
        $result = '';
        $cursor = 0;

        foreach ($merged as [$start, $end]) {
            $result .= substr($escaped, $cursor, $start - $cursor);
            $result .= '<mark>'.substr($escaped, $start, $end - $start).'</mark>';
            $cursor = $end;
        }

        $result .= substr($escaped, $cursor);

        return $result;
    }
}
