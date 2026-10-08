<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\ChurchServiceTranscript;
use App\Models\Song;

/**
 * Whether a song section's own sung words contradict the song it is bound to.
 *
 * The §4.1a lyric-identity census (2026-09-14, `residue-20260914-lyric-identity.py`)
 * scored each song section's transcript against every catalogue song's lyrics. At the
 * rule below it flagged 5 of 7 known-wrong bindings and 0 of 38 frame-verified correct
 * ones; the two misses had looping transcripts. It then found 15 published clips that
 * slides confirmed were all wrong. The rule is ported unchanged so that calibration
 * still describes it.
 *
 * A contradiction needs a rival song that clearly wins: at least
 * {@see self::MINIMUM_RIVAL_WORD_PAIRS} shared word pairs, twice the bound song's, and
 * {@see self::MINIMUM_COVERAGE_LEAD} more IDF-weighted word coverage. It is a
 * contradiction test, not a confirmation: Whisper drops much singing, so a section
 * whose transcript heard little is left undecided rather than doubted.
 */
final class SongLyricIdentityCheck
{
    public const CONTRADICTED = 'contradicted';

    public const CONSISTENT = 'consistent';

    public const INSUFFICIENT_WORDS = 'insufficient_words';

    /** The leader's announcement and the introduction fall in a section's first seconds. */
    private const LEAD_IN_SECONDS = 8.0;

    /** The next item's first words fall in a section's last seconds. */
    private const TAIL_SECONDS = 5.0;

    private const MINIMUM_DISTINCT_WORDS = 15;

    private const MINIMUM_RIVAL_WORD_PAIRS = 4;

    private const MINIMUM_COVERAGE_LEAD = 0.1;

    private const STOP_WORDS = [
        'the', 'and', 'you', 'your', 'our', 'for', 'with', 'that', 'this', 'are', 'his', 'him', 'her',
        'was', 'all', 'who', 'will', 'from', 'have', 'has', 'not', 'but', 'they', 'them', 'their',
        'thou', 'thee', 'thy', 'what', 'when', 'where', 'which', 'into', 'unto', 'shall', 'may', 'can',
        'one', 'out', 'now', 'let', 'see', 'how', 'its', "let's", 'yes', 'did', 'were', 'been', 'being', 'yet', 'till',
    ];

    /** @var array{vocabulary: array<int, array<string, true>>, pairs: array<int, array<string, true>>, idf: array<string, float>, titles: array<int, string>, unseen_idf: float}|null */
    private ?array $catalogue = null;

    /**
     * @return array{
     *     verdict: self::CONTRADICTED|self::CONSISTENT|self::INSUFFICIENT_WORDS,
     *     words: int,
     *     bound_song_id: int,
     *     bound_score: array{coverage: float, word_pairs: int},
     *     rival_song_id: int|null,
     *     rival_title: string|null,
     *     rival_score: array{coverage: float, word_pairs: int}|null,
     *     leading_song_id: int|null,
     *     leading_score: array{coverage: float, word_pairs: int}|null,
     *     contradicted_part?: array{0: float, 1: float}
     * }
     */
    public function assess(ChurchServiceTranscript $transcript, float $start, float $end, int $boundSongId): array
    {
        $whole = $this->assessSpan($transcript, $start, $end, $boundSongId);

        if ($whole['verdict'] !== self::CONSISTENT) {
            return $whole;
        }

        // Two songs in one section can each hold their own across the whole span: 1012 §1288 opened
        // on "God Of Glory" before the bound song (canary 13). Split at the longest pause between
        // its lines, a part another song clearly wins contradicts the binding. Measured 2026-10-08
        // over the local corpus: 1 of 25 sections with enough words in both parts, the known 1012.
        $cues = array_values(array_filter($transcript->cues, static fn (array $cue): bool => $cue['start'] >= $start && $cue['end'] <= $end));
        $split = null;
        $longest = 0.0;

        for ($index = 1; $index < count($cues); $index++) {
            $gap = $cues[$index]['start'] - $cues[$index - 1]['end'];

            if ($gap > $longest) {
                [$longest, $split] = [$gap, ($cues[$index - 1]['end'] + $cues[$index]['start']) / 2];
            }
        }

        if ($split === null) {
            return $whole;
        }

        $parts = [[$start, $split], [$split, $end]];
        $assessed = array_map(fn (array $part): array => $this->assessSpan($transcript, $part[0], $part[1], $boundSongId), $parts);
        $verdicts = array_column($assessed, 'verdict');

        if (in_array(self::INSUFFICIENT_WORDS, $verdicts, true) || count(array_keys($verdicts, self::CONTRADICTED, true)) !== 1) {
            return $whole;
        }

        $part = array_search(self::CONTRADICTED, $verdicts, true);

        return [
            ...$whole,
            'verdict' => self::CONTRADICTED,
            'rival_song_id' => $assessed[$part]['rival_song_id'],
            'rival_title' => $assessed[$part]['rival_title'],
            'rival_score' => $assessed[$part]['rival_score'],
            'contradicted_part' => $parts[$part],
        ];
    }

    /**
     * @return array{
     *     verdict: self::CONTRADICTED|self::CONSISTENT|self::INSUFFICIENT_WORDS,
     *     words: int,
     *     bound_song_id: int,
     *     bound_score: array{coverage: float, word_pairs: int},
     *     rival_song_id: int|null,
     *     rival_title: string|null,
     *     rival_score: array{coverage: float, word_pairs: int}|null,
     *     leading_song_id: int|null,
     *     leading_score: array{coverage: float, word_pairs: int}|null
     * }
     */
    private function assessSpan(ChurchServiceTranscript $transcript, float $start, float $end, int $boundSongId): array
    {
        $words = self::contentWords($this->sungText($transcript, $start, $end));
        $distinct = array_fill_keys($words, true);
        $pairs = self::wordPairs($words);
        $catalogue = $this->catalogue();

        $total = 0.0;
        foreach (array_keys($distinct) as $word) {
            $total += $catalogue['idf'][$word] ?? $catalogue['unseen_idf'];
        }
        $total = $total > 0.0 ? $total : 1.0;

        $score = function (int $songId) use ($catalogue, $distinct, $pairs, $total): array {
            $covered = 0.0;
            foreach (array_intersect_key($distinct, $catalogue['vocabulary'][$songId] ?? []) as $word => $_) {
                $covered += $catalogue['idf'][$word];
            }

            return [
                'coverage' => round($covered / $total, 4),
                'word_pairs' => count(array_intersect_key($pairs, $catalogue['pairs'][$songId] ?? [])),
            ];
        };

        $boundScore = $score($boundSongId);
        $result = [
            'verdict' => self::CONSISTENT,
            'words' => count($distinct),
            'bound_song_id' => $boundSongId,
            'bound_score' => $boundScore,
            'rival_song_id' => null,
            'rival_title' => null,
            'rival_score' => null,
            'leading_song_id' => null,
            'leading_score' => null,
        ];

        if (count($distinct) < self::MINIMUM_DISTINCT_WORDS) {
            return ['verdict' => self::INSUFFICIENT_WORDS] + $result;
        }

        $topId = null;
        $topScore = null;
        foreach (array_keys($catalogue['vocabulary']) as $songId) {
            $candidate = $score($songId);

            if ($topScore === null
                || $candidate['word_pairs'] > $topScore['word_pairs']
                || ($candidate['word_pairs'] === $topScore['word_pairs'] && $candidate['coverage'] > $topScore['coverage'])) {
                $topId = $songId;
                $topScore = $candidate;
            }
        }

        $result = ['leading_song_id' => $topId, 'leading_score' => $topScore] + $result;

        if ($topId === null || $topId === $boundSongId
            || $topScore['word_pairs'] < self::MINIMUM_RIVAL_WORD_PAIRS
            || $topScore['word_pairs'] < 2 * max($boundScore['word_pairs'], 1)
            || $topScore['coverage'] < $boundScore['coverage'] + self::MINIMUM_COVERAGE_LEAD) {
            return $result;
        }

        return [
            'verdict' => self::CONTRADICTED,
            'rival_song_id' => $topId,
            'rival_title' => $catalogue['titles'][$topId],
            'rival_score' => $topScore,
        ] + $result;
    }

    /**
     * Whether the sung words positively name the bound song: no song in the catalogue
     * scores above it, and it shares at least {@see self::MINIMUM_RIVAL_WORD_PAIRS} word
     * pairs — the bar a rival has to clear to contradict a binding.
     *
     * The converse of {@see self::assess()}'s contradiction, and deliberately harder to
     * reach: a hold this scorer raised clears only on this, never on a merely
     * undecided reading, because Whisper drops much singing.
     *
     * @param  array<string, mixed>  $assessment  A result of {@see self::assess()}
     */
    public static function confirms(array $assessment): bool
    {
        $bound = $assessment['bound_score'] ?? null;
        $leading = $assessment['leading_score'] ?? null;

        return ($assessment['verdict'] ?? null) === self::CONSISTENT
            && is_array($bound) && is_array($leading)
            && $bound['word_pairs'] >= self::MINIMUM_RIVAL_WORD_PAIRS
            && $bound['word_pairs'] === $leading['word_pairs']
            && $bound['coverage'] >= $leading['coverage'];
    }

    private function sungText(ChurchServiceTranscript $transcript, float $start, float $end): string
    {
        $lines = [];

        foreach ($transcript->cues as $cue) {
            if ($cue['start'] >= $start + self::LEAD_IN_SECONDS && $cue['start'] < $end - self::TAIL_SECONDS) {
                $lines[] = $cue['text'];
            }
        }

        return implode(' ', $lines);
    }

    /**
     * Built once per instance: the job checks every section of a run against the same catalogue.
     *
     * @return array{vocabulary: array<int, array<string, true>>, pairs: array<int, array<string, true>>, idf: array<string, float>, titles: array<int, string>, unseen_idf: float}
     */
    private function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        $vocabulary = [];
        $pairs = [];
        $titles = [];
        $documentFrequency = [];

        foreach (Song::query()->select(['id', 'title', 'lyrics_plain'])->orderBy('id')->cursor() as $song) {
            $words = self::contentWords($song->lyrics_plain.' '.$song->title);
            $vocabulary[$song->id] = array_fill_keys($words, true);
            $pairs[$song->id] = self::wordPairs($words);
            $titles[$song->id] = (string) $song->title;

            foreach ($vocabulary[$song->id] as $word => $_) {
                $documentFrequency[$word] = ($documentFrequency[$word] ?? 0) + 1;
            }
        }

        $songCount = count($vocabulary);
        $idf = [];
        foreach ($documentFrequency as $word => $frequency) {
            $idf[$word] = log(($songCount + 1) / ($frequency + 1));
        }

        return $this->catalogue = [
            'vocabulary' => $vocabulary,
            'pairs' => $pairs,
            'idf' => $idf,
            'titles' => $titles,
            'unseen_idf' => log($songCount + 1),
        ];
    }

    /**
     * Lower-cased words of three letters or more, stop words removed: the census's tokeniser.
     *
     * @return list<string>
     */
    public static function contentWords(string $text): array
    {
        preg_match_all("/[a-z']+/", str_replace('’', "'", mb_strtolower($text)), $matches);

        return array_values(array_filter(
            $matches[0],
            static fn (string $word): bool => strlen($word) >= 3 && ! in_array($word, self::STOP_WORDS, true),
        ));
    }

    /**
     * Adjacent content-word pairs, keyed for set intersection.
     *
     * @param  list<string>  $words
     * @return array<string, true>
     */
    public static function wordPairs(array $words): array
    {
        $pairs = [];

        for ($index = 1, $count = count($words); $index < $count; $index++) {
            $pairs[$words[$index - 1].' '.$words[$index]] = true;
        }

        return $pairs;
    }
}
