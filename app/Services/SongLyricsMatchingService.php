<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Song;

class SongLyricsMatchingService
{
    /**
     * Number of characters from the start of lyrics_plain to compare against.
     */
    private const int LYRICS_COMPARISON_LENGTH = 200;

    /**
     * Given a short transcript excerpt (e.g. first 30s of a song), attempt to match
     * it against the Song catalog using canonical key lookup then fuzzy lyrics matching.
     *
     * Returns the matched song ID, confidence score (0.0–1.0), and matched title.
     * Returns null song_id when no match meets the minimum threshold.
     *
     * @return array{song_id: int|null, confidence: float, matched_title: string|null}
     */
    public function matchFromLyrics(string $transcript): array
    {
        $transcript = trim($transcript);

        if ($transcript === '') {
            return $this->noMatch();
        }

        // 1. Try canonical key lookup on the first line of the transcript.
        $canonicalMatch = $this->tryCanonicalKeyLookup($transcript);
        if ($canonicalMatch !== null) {
            return $canonicalMatch;
        }

        // 2. Fuzzy lyrics comparison against songs with lyrics_plain.
        return $this->fuzzyLyricsMatch($transcript);
    }

    /**
     * @return array{song_id: int|null, confidence: float, matched_title: string|null}|null
     */
    private function tryCanonicalKeyLookup(string $transcript): ?array
    {
        $firstLine = $this->extractFirstLine($transcript);
        if ($firstLine === '') {
            return null;
        }

        $key = Song::canonicalizeKey($firstLine);
        if ($key === '') {
            return null;
        }

        $song = Song::query()
            ->where('canonical_key', $key)
            ->first();

        if ($song instanceof Song) {
            return [
                'song_id' => $song->id,
                'confidence' => 1.0,
                'matched_title' => $song->title,
            ];
        }

        return null;
    }

    /**
     * @return array{song_id: int|null, confidence: float, matched_title: string|null}
     */
    private function fuzzyLyricsMatch(string $transcript): array
    {
        $threshold = (float) config('media-processing.song_matching.lyrics_threshold', 0.6);
        $transcriptNormalized = $this->normalize($transcript);

        if ($transcriptNormalized === '') {
            return $this->noMatch();
        }

        $bestScore = 0.0;
        $bestSong = null;

        /** @var \Illuminate\Database\Eloquent\Collection<int, Song> $songs */
        $songs = Song::query()
            ->whereNotNull('lyrics_plain')
            ->select(['id', 'title', 'lyrics_plain'])
            ->get();

        foreach ($songs as $song) {
            if (! is_string($song->lyrics_plain) || $song->lyrics_plain === '') {
                continue;
            }

            $lyricsExcerpt = $this->normalize(
                substr($song->lyrics_plain, 0, self::LYRICS_COMPARISON_LENGTH)
            );

            if ($lyricsExcerpt === '') {
                continue;
            }

            similar_text($transcriptNormalized, $lyricsExcerpt, $percent);
            $score = $percent / 100.0;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSong = $song;
            }
        }

        if ($bestScore >= $threshold && $bestSong instanceof Song) {
            return [
                'song_id' => $bestSong->id,
                'confidence' => round($bestScore, 4),
                'matched_title' => $bestSong->title,
            ];
        }

        return $this->noMatch();
    }

    /**
     * Normalise text for comparison: lowercase, collapse whitespace, strip punctuation.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Extract the first non-empty line from the transcript.
     */
    private function extractFirstLine(string $transcript): string
    {
        $lines = preg_split('/\r?\n/', $transcript) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @return array{song_id: null, confidence: 0.0, matched_title: null}
     */
    private function noMatch(): array
    {
        return [
            'song_id' => null,
            'confidence' => 0.0,
            'matched_title' => null,
        ];
    }
}
