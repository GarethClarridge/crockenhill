<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Models\Song;
use Illuminate\Database\Eloquent\Collection;

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
     * The first-line-key shortcut (matching the probe's opening line against a
     * song's stored first lyric line) only holds when the probe genuinely
     * starts at the song opening — a title hint or a song-opening transcript.
     * OCR frames can be sampled anywhere in the song, so their first visible
     * line is not the opening; callers pass $allowFirstLineKeyMatch = false to
     * skip the shortcut and let fuzzy matching weigh the whole sample.
     *
     * @return array{song_id: int|null, confidence: float, matched_title: string|null}
     */
    public function matchFromLyrics(string $transcript, bool $allowFirstLineKeyMatch = true): array
    {
        $transcript = trim($transcript);

        if ($transcript === '') {
            return $this->noMatch();
        }

        // 1. Try canonical key lookup on the first line of the transcript.
        $canonicalMatch = $this->tryCanonicalKeyLookup($transcript, $allowFirstLineKeyMatch);
        if ($canonicalMatch !== null) {
            return $canonicalMatch;
        }

        // 2. Fuzzy lyrics comparison against songs with lyrics_plain.
        return $this->fuzzyLyricsMatch($transcript);
    }

    /**
     * @return array{song_id: int|null, confidence: float, matched_title: string|null}|null
     */
    private function tryCanonicalKeyLookup(string $transcript, bool $allowFirstLineKeyMatch): ?array
    {
        $firstLine = $this->extractFirstLine($transcript);
        if ($firstLine === '') {
            return null;
        }

        $keyVariants = Song::matchKeyVariants($firstLine);
        if ($keyVariants === []) {
            return null;
        }

        $song = Song::query()
            ->whereIn('canonical_key', $keyVariants)
            ->first();

        if ($song instanceof Song) {
            return [
                'song_id' => $song->id,
                'confidence' => 1.0,
                'matched_title' => $song->title,
            ];
        }

        // The heard opening is often the first lyric line rather than the
        // catalogued title ("What love could remember" vs "His Mercy Is
        // More"); slightly lower confidence than an exact title match. Only
        // trust this when the probe actually starts at the opening — an OCR
        // frame's first line may be a mid-song verse (guarded by the caller).
        // first_line_key is not unique — when two songs share an opening
        // line, fall through to fuzzy matching rather than pick arbitrarily.
        if (! $allowFirstLineKeyMatch) {
            return null;
        }

        $firstLineMatches = Song::query()
            ->whereIn('first_line_key', $keyVariants)
            ->limit(2)
            ->get();

        if ($firstLineMatches->count() === 1) {
            $song = $firstLineMatches->sole();

            return [
                'song_id' => $song->id,
                'confidence' => 0.95,
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

        /** @var Collection<int, Song> $songs */
        $songs = Song::query()
            ->whereNotNull('lyrics_plain')
            ->select(['id', 'title', 'lyrics_plain'])
            ->get();

        foreach ($songs as $song) {
            if (! is_string($song->lyrics_plain) || $song->lyrics_plain === '') {
                continue;
            }

            $lyricsNormalized = $this->normalize($song->lyrics_plain);

            if ($lyricsNormalized === '') {
                continue;
            }

            $score = $this->bestWindowScore($transcriptNormalized, $lyricsNormalized);

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
     * Score the probe against the best-matching window across the full lyrics.
     *
     * OCR frames and opening transcripts can capture any verse of a song, so the
     * probe is compared against overlapping windows over the whole lyrics rather
     * than only the opening lines.
     */
    private function bestWindowScore(string $probe, string $lyrics): float
    {
        if (str_contains($lyrics, $probe)) {
            return 1.0;
        }

        $windowLength = max(strlen($probe), self::LYRICS_COMPARISON_LENGTH);
        $lyricsLength = strlen($lyrics);
        $step = max(1, intdiv($windowLength, 2));
        $best = 0.0;

        for ($offset = 0; $offset < $lyricsLength; $offset += $step) {
            $window = substr($lyrics, $offset, $windowLength);
            similar_text($probe, $window, $percent);
            $best = max($best, $percent / 100.0);

            if ($offset + $windowLength >= $lyricsLength) {
                break;
            }
        }

        return $best;
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
