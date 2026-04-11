<?php

declare(strict_types=1);

namespace App\Services;

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
            if (! str_contains($lower, $token)) {
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
                if (str_contains($lower, $token)) {
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
     * @param  Collection<int, non-empty-string>  $tokens
     */
    private function highlight(string $line, Collection $tokens): string
    {
        $escaped = e($line);

        foreach ($tokens as $token) {
            $escaped = (string) preg_replace_callback(
                '/'.preg_quote(e($token), '/').'/iu',
                fn (array $m): string => '<mark>'.($m[0]).'</mark>',
                $escaped
            );
        }

        return $escaped;
    }
}
