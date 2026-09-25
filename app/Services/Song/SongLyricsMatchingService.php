<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\SongTitleMatch;
use App\Models\Song;
use Illuminate\Database\Eloquent\Collection;

class SongLyricsMatchingService
{
    /**
     * Number of characters from the start of lyrics_plain to compare against.
     */
    private const int LYRICS_COMPARISON_LENGTH = 200;

    /**
     * Resolver rungs that name a song by a catalogued title rather than by
     * resemblance, and so must outrank a lyrics comparison.
     *
     * {@see SongTitleMatch::TYPE_FIRST_LINE} is deliberately absent: a first-line
     * hint is already answered below at a lower confidence (0.95) than a title,
     * because the opening line a congregation sings is weaker evidence of which
     * catalogue row was meant than the title itself. Taking the resolver's
     * first-line rung here would silently promote those matches to 1.0.
     * {@see SongTitleMatch::TYPE_FUZZY} and {@see SongTitleMatch::TYPE_HYMNBOOK_ABSENT}
     * are absent because both are resemblance, which the lyrics comparison
     * judges better for a heard line.
     */
    private const array CATALOGUE_TITLE_MATCH_TYPES = [
        SongTitleMatch::TYPE_EXACT,
        SongTitleMatch::TYPE_PRAISE_NUMBER,
        SongTitleMatch::TYPE_STRIPPED_NUMBER,
        SongTitleMatch::TYPE_LOOSE_TITLE,
        SongTitleMatch::TYPE_ALTERNATE_TITLE,
    ];

    /**
     * Built once per instance: the catalogue does not change within a run, and
     * {@see SongTitleResolver::fromDatabase()} reads every song to build it.
     */
    private ?SongTitleResolver $titleResolver = null;

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
        return $this->lyricsMatch($transcript, $allowFirstLineKeyMatch, refuseTiedHymns: false);
    }

    /**
     * A hint that names no catalogued title falls back to the lyrics comparison, which
     * scores bare containment as 1.0. When the phrase sits verbatim in several different
     * hymns, every one of them ties. The tie goes to the one hymn it still names
     * {@see self::hymnTheTieNames()}; otherwise the fallback refuses rather than hand the section
     * to whichever row is scanned first ("Jesus Is Lord" titles two hymns and appears in four
     * more). Rows of one hymn — a numbered and an unnumbered copy sharing a first line — tie
     * as one and still match.
     *
     * @return array{song_id: int|null, confidence: float, matched_title: string|null, match_source: string|null}
     */
    public function matchTitleHint(string $titleHint): array
    {
        $catalogued = $this->catalogueTitleMatch($titleHint);

        if ($catalogued !== null) {
            return $catalogued;
        }

        $result = $this->lyricsMatch($titleHint, allowFirstLineKeyMatch: true, refuseTiedHymns: true);

        if ($result['song_id'] === null) {
            return [...$result, 'match_source' => null];
        }

        $keys = Song::matchKeyVariants($this->extractFirstLine($titleHint));
        $isCanonicalTitle = $keys !== [] && Song::query()->whereIn('canonical_key', $keys)->exists();

        if ($isCanonicalTitle) {
            return [...$result, 'match_source' => 'title_hint_canonical'];
        }

        $firstLineMatches = $keys === []
            ? new Collection
            : Song::query()->whereIn('first_line_key', $keys)->limit(2)->get();

        return [...$result, 'match_source' => $firstLineMatches->count() === 1 ? 'title_hint_first_line' : 'title_hint_fuzzy'];
    }

    /**
     * The song a hint names outright — by its catalogued title, by the Praise!
     * number form of that title, or by an alternate title.
     *
     * A title hint is a *title*, but {@see self::matchFromLyrics()} scores it
     * against lyrics bodies, and {@see self::bestWindowScore()} returns 1.0 on
     * bare containment. So a hymn quoted inside another song's verse — "O
     * blessed Rock of ages, I'm hiding in you", in Praise! 887 — ties with the
     * hymn the hint actually names, and whichever row is scanned first wins.
     * The named hymn's own title carries a trailing hymn number ("rock of ages
     * 705"), so the exact-key rung never reaches it and the quotation takes the
     * clip. A tie at 1.0 also clears the write-back threshold, so the wrong song
     * is stored as Confirmed and its title replaces the heard text.
     *
     * {@see SongTitleResolver} already answers this question for the order of
     * service: it strips the trailing number, indexes alternate titles, and
     * drops any key two songs share rather than guessing between them. Only its
     * deterministic rungs are taken {@see self::CATALOGUE_TITLE_MATCH_TYPES}, so
     * a hint naming no catalogued title still falls through to the lyrics
     * comparison — which is what resolves a heard line such as "The Servant
     * King", catalogued only as "From Heaven You Came #396".
     *
     * @return array{song_id: int, confidence: float, matched_title: string|null, match_source: string}|null
     */
    private function catalogueTitleMatch(string $titleHint): ?array
    {
        $resolver = $this->titleResolver ??= SongTitleResolver::fromDatabase();

        $match = $resolver->resolve($titleHint);

        if (! $match instanceof SongTitleMatch) {
            return null;
        }

        if (! in_array($match->matchType, self::CATALOGUE_TITLE_MATCH_TYPES, true)) {
            return null;
        }

        return [
            'song_id' => $match->songId,
            'confidence' => $match->confidence,
            'matched_title' => $resolver->catalogueTitle($match->songId),
            'match_source' => $match->matchType === SongTitleMatch::TYPE_EXACT
                ? 'title_hint_canonical'
                : 'title_hint_catalogue_title',
        ];
    }

    /**
     * @return array{song_id: int|null, confidence: float, matched_title: string|null}
     */
    private function lyricsMatch(string $transcript, bool $allowFirstLineKeyMatch, bool $refuseTiedHymns): array
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
        return $this->fuzzyLyricsMatch($transcript, $refuseTiedHymns);
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
    private function fuzzyLyricsMatch(string $transcript, bool $refuseTiedHymns): array
    {
        $threshold = (float) config('media-processing.song_matching.lyrics_threshold', 0.6);
        $transcriptNormalized = $this->normalize($transcript);

        if ($transcriptNormalized === '') {
            return $this->noMatch();
        }

        $bestScore = 0.0;
        $bestSong = null;
        /** @var array<string, array{song: Song, lyrics: string}> $hymnsAtBestScore */
        $hymnsAtBestScore = [];

        /** @var Collection<int, Song> $songs */
        $songs = Song::query()
            ->whereNotNull('lyrics_plain')
            ->select(['id', 'title', 'lyrics_plain', 'first_line_key'])
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
                $hymnsAtBestScore = [$this->hymnIdentity($song) => ['song' => $song, 'lyrics' => $lyricsNormalized]];
            } elseif ($bestSong instanceof Song && $score === $bestScore) {
                $hymnsAtBestScore[$this->hymnIdentity($song)] ??= ['song' => $song, 'lyrics' => $lyricsNormalized];
            }
        }

        if ($refuseTiedHymns && count($hymnsAtBestScore) > 1) {
            $bestSong = $this->hymnTheTieNames($transcriptNormalized, $hymnsAtBestScore);

            if (! $bestSong instanceof Song) {
                return $this->noMatch();
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
     * The one hymn a tied phrase still names: the only tied hymn whose title carries it
     * ("Take My Life" titles one of four hymns that sing it), else the hymn that sings it as a
     * refrain — at least three times and twice as often as any rival ("The Servant King", eight
     * times in From Heaven You Came, once in All Praise To Him). Anything less is a guess.
     *
     * @param  array<string, array{song: Song, lyrics: string}>  $tied
     */
    private function hymnTheTieNames(string $probe, array $tied): ?Song
    {
        $titled = array_values(array_filter(
            $tied,
            fn (array $candidate): bool => str_contains($this->normalize((string) $candidate['song']->title), $probe),
        ));

        if (count($titled) === 1) {
            return $titled[0]['song'];
        }

        if (count($titled) > 1) {
            return null;
        }

        $occurrences = array_map(
            static fn (array $candidate): int => substr_count($candidate['lyrics'], $probe),
            array_values($tied),
        );
        arsort($occurrences);
        $counts = array_values($occurrences);
        $leader = (int) array_key_first($occurrences);

        if ($counts[0] >= 3 && $counts[0] >= 2 * ($counts[1] ?? 0)) {
            return array_values($tied)[$leader]['song'];
        }

        return null;
    }

    /**
     * Rows sharing a first line are one hymn catalogued twice ("Here Is Love #424" and "Here
     * Is Love"), so a tie between them names one hymn, not a choice between two. The stored
     * first line is compared as written: its punctuation separates hymns that merely open alike
     * ("jesus is lord!" and "'jesus is lord' -" are different hymns).
     */
    private function hymnIdentity(Song $song): string
    {
        $firstLine = trim((string) $song->first_line_key);

        return $firstLine !== '' ? "first-line:{$firstLine}" : "song:{$song->id}";
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
