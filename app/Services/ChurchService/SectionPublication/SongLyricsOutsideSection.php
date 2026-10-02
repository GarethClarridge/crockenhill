<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\Structure\SongLyricEdgeExtension;
use App\Services\Media\Audio\SustainedSound;

/**
 * A song's own lyrics, sung just outside its section.
 *
 * The §4.1b song-edge census (2026-09-14) found clips losing their own verses to a neighbour:
 * the last verse inside the following prayer (clip 240), the opening inside a reading (539),
 * the first lines inside the tail of the previous song (360, 207). The clip's length was only
 * ever checked against its own span, so none of them was flagged.
 *
 * A transcript line within {@see self::WINDOW_SECONDS} outside the section counts as this song
 * being sung when it shares content-word pairs with the song's lyrics, is not the leader
 * announcing the song, and — inside another song's section — fits this song better than that
 * song's own lyrics. Stock phrases ("praise him", "adore him") are what that last test is for.
 * An edge is a risk when its lines carry {@see self::MINIMUM_WORD_PAIRS} distinct pairs and the
 * sound under them is sustained: a prayer or talk quoting the hymn pauses between phrases.
 *
 * Measured by fresh audio over the corpus on 2026-09-15: 41 edges, 23 of them real. Inside
 * another song's section 12 of 16 were real, unsectioned 6 of 7, inside a prayer, reading,
 * welcome or other section 5 of 14 — the misses there are announcements and readings over
 * music. The operator chose to hold all three, because a missed edge publishes a clip without
 * its verses. Transcripts miss much singing (clip 192's "heaven-born" was never transcribed),
 * so this is a floor, not a census.
 */
final class SongLyricsOutsideSection
{
    public const RISK_KIND = 'song_lyrics_outside_section';

    private const WINDOW_SECONDS = 90.0;

    private const MINIMUM_WORD_PAIRS = 2;

    private const MINIMUM_SUSTAINED_SHARE = 0.5;

    /** A cue's start time stands for a line; the sound is judged a few seconds past it. */
    private const LINE_SECONDS = 6.0;

    /**
     * A line repeated more often than this inside one edge window is a transcription loop, and a
     * looping transcript cannot supply lyric evidence (§4.3). 1266 §3332: "judge… take children
     * home" 37 times over a different song shared three pairs with "Jesus Is Lord". Real refrains
     * in the corpus repeat two or three times (1288, 1200).
     */
    private const MAXIMUM_LINE_REPEATS = 3;

    /**
     * A leader naming the song before it starts, over the introduction (1224, 1127, 978).
     * Deliberately narrower than "sing": sung lines say "sing" too (240's "Still my soul, sing
     * your praise unending").
     */
    private const ANNOUNCEMENT_PATTERN = '/(going to sing|let\'?s (stand|sing)|shall we sing|we\'?ll sing|hymn number|number \d|verse reads|first verse|next hymn|our (next|closing|opening) (hymn|song))/i';

    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'by', 'for', 'from', 'with',
        'is', 'are', 'was', 'be', 'been', 'am', 'do', 'did', 'have', 'has', 'it', 'its', 'this', 'that',
        'we', 'you', 'he', 'she', 'they', 'i', 'me', 'my', 'our', 'your', 'his', 'her', 'their', 'us',
        'them', 'so', 'as', 'oh', 'o', 'all', 'will', 'shall',
    ];

    /**
     * One observation per edge whose outside lines share at least two word pairs with the song.
     *
     * @return list<array{
     *     edge: 'before'|'after',
     *     holder: string,
     *     lines: int,
     *     word_pairs: list<string>,
     *     span: array{start: float, end: float},
     *     sustained_share: float,
     *     risk: bool,
     *     detail: string
     * }>
     */
    public function observe(ServiceSection $section, ChurchServiceTranscript $transcript, ?SustainedSound $sound): array
    {
        $songId = $section->resolvedSongId();

        if ($songId === null) {
            return [];
        }

        $others = array_values($section->processingLog->serviceSections()
            ->where('id', '!=', $section->id)
            ->get()
            ->all());
        $songIds = [$songId];

        foreach ($others as $other) {
            if ($other->section_type === ServiceSectionType::Song && $other->resolvedSongId() !== null) {
                $songIds[] = $other->resolvedSongId();
            }
        }

        $lyrics = Song::query()->whereKey(array_unique($songIds))->pluck('lyrics_plain', 'id')
            ->map(fn (?string $text): array => self::wordPairs($text))
            ->all();
        $ownPairs = $lyrics[$songId] ?? [];

        if ($ownPairs === []) {
            return [];
        }

        $start = (float) $section->start_time;
        $end = (float) $section->end_time;
        $observations = [];

        foreach (['before' => [$start - self::WINDOW_SECONDS, $start], 'after' => [$end, $end + self::WINDOW_SECONDS]] as $edge => [$from, $to]) {
            $observation = $this->observeEdge($edge, $from, $to, $transcript, $others, $songId, $ownPairs, $lyrics, $sound);

            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        return $observations;
    }

    /**
     * What {@see self::observe()} reads beyond the transcript and the sound: the song,
     * the run's other sections that can hold an edge, and the lyrics of every song among
     * them. Banked evidence fingerprints this so a retyped neighbour or edited lyrics
     * make it stale.
     *
     * @return array{
     *     song_id: int|null,
     *     others: list<array{id: int, section_type: string, start_time: float, end_time: float, song_id: int|null}>,
     *     lyrics_sha256: array<int, string>
     * }
     */
    public function inputs(ServiceSection $section): array
    {
        $others = $section->processingLog->serviceSections()
            ->where('id', '!=', $section->id)
            ->orderBy('id')
            ->get();

        $summaries = array_values($others->map(fn (ServiceSection $other): array => [
            'id' => $other->id,
            'section_type' => $other->section_type->value,
            'start_time' => (float) $other->start_time,
            'end_time' => (float) $other->end_time,
            'song_id' => $other->section_type === ServiceSectionType::Song ? $other->resolvedSongId() : null,
        ])->all());

        $songIds = array_values(array_unique(array_filter([
            $section->resolvedSongId(),
            ...array_column($summaries, 'song_id'),
        ], static fn (?int $songId): bool => $songId !== null)));

        $lyrics = Song::query()
            ->whereKey($songIds)
            ->orderBy('id')
            ->pluck('lyrics_plain', 'id')
            ->map(static fn (?string $text): string => hash('sha256', (string) $text))
            ->all();

        return [
            'song_id' => $section->resolvedSongId(),
            'others' => $summaries,
            'lyrics_sha256' => $lyrics,
        ];
    }

    /**
     * @param  'before'|'after'  $edge
     * @param  list<ServiceSection>  $others
     * @param  array<string, true>  $ownPairs
     * @param  array<int, array<string, true>>  $lyrics
     * @return array{edge: 'before'|'after', holder: string, lines: int, word_pairs: list<string>, span: array{start: float, end: float}, sustained_share: float, risk: bool, detail: string}|null
     */
    private function observeEdge(
        string $edge,
        float $from,
        float $to,
        ChurchServiceTranscript $transcript,
        array $others,
        int $songId,
        array $ownPairs,
        array $lyrics,
        ?SustainedSound $sound,
    ): ?array {
        $lines = 0;
        $spanStart = INF;
        $spanEnd = -INF;
        $pairs = [];
        $firstHolder = null;
        $windowCues = array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => $cue['start'] >= $from && $cue['start'] < $to,
        ));
        $repeats = array_count_values(array_map(
            static fn (array $cue): string => trim((string) preg_replace('/[^a-z]+/', ' ', mb_strtolower($cue['text']))),
            $windowCues,
        ));

        foreach ($windowCues as $cue) {
            $normalised = trim((string) preg_replace('/[^a-z]+/', ' ', mb_strtolower($cue['text'])));

            if (($repeats[$normalised] ?? 0) > self::MAXIMUM_LINE_REPEATS || preg_match(self::ANNOUNCEMENT_PATTERN, $cue['text']) === 1) {
                continue;
            }

            $linePairs = self::wordPairs($cue['text']);
            $shared = array_intersect_key($linePairs, $ownPairs);

            if ($shared === []) {
                continue;
            }

            $holder = $this->holderAt($others, $cue['start']);
            $holderSongId = $holder?->section_type === ServiceSectionType::Song ? $holder->resolvedSongId() : null;

            if ($holderSongId === $songId) {
                continue;
            }

            if ($holderSongId !== null && count($shared) <= count(array_intersect_key($linePairs, $lyrics[$holderSongId] ?? []))) {
                continue;
            }

            $lines++;
            $spanStart = min($spanStart, $cue['start']);
            $spanEnd = max($spanEnd, $cue['start'] + self::LINE_SECONDS);
            $pairs += $shared;
            $firstHolder ??= $holder === null ? 'unsectioned time' : sprintf('section %d (%s)', $holder->id, $holder->section_type->value);
        }

        if (count($pairs) < self::MINIMUM_WORD_PAIRS || $firstHolder === null) {
            return null;
        }

        $share = $sound?->share($spanStart, $spanEnd) ?? 0.0;
        $risk = $share >= self::MINIMUM_SUSTAINED_SHARE;

        return [
            'edge' => $edge,
            'holder' => $firstHolder,
            'lines' => $lines,
            'word_pairs' => array_keys($pairs),
            'span' => ['start' => $spanStart, 'end' => $spanEnd],
            'sustained_share' => round($share, 2),
            'risk' => $risk,
            'detail' => sprintf(
                '%d transcript line(s) %s the clip, inside %s at %.1fs-%.1fs, share %d word pairs with this song\'s lyrics%s.',
                $lines,
                $edge,
                $firstHolder,
                $spanStart,
                $spanEnd,
                count($pairs),
                $risk
                    ? ' over sustained sound, so the clip may leave out part of its own song'
                    : '; the sound under them pauses like speech, so they read as a quotation',
            ),
        ];
    }

    /**
     * @param  list<ServiceSection>  $others
     */
    private function holderAt(array $others, float $time): ?ServiceSection
    {
        foreach ($others as $other) {
            if ((float) $other->start_time <= $time && (float) $other->end_time > $time) {
                return $other;
            }
        }

        return null;
    }

    /**
     * Adjacent content-word pairs, so "praise him" matches wherever the words sit together.
     * {@see SongLyricEdgeExtension} reads lyrics the same way,
     * so a line that raised this hold is a line the correction can act on.
     *
     * @return array<string, true>
     */
    public static function wordPairs(?string $text): array
    {
        preg_match_all('/[a-z]+/', mb_strtolower((string) $text), $matches);
        $words = array_values(array_filter(
            $matches[0],
            static fn (string $word): bool => strlen($word) > 1 && ! in_array($word, self::STOP_WORDS, true),
        ));
        $pairs = [];

        for ($index = 0; $index + 1 < count($words); $index++) {
            $pairs[$words[$index].' '.$words[$index + 1]] = true;
        }

        return $pairs;
    }
}
