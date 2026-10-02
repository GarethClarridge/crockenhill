<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\ChurchServiceTranscript;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\Structure\SongLyricEdgeExtension;
use App\Services\Media\Audio\SustainedSound;
use App\Services\Song\OpenLpLyricsParser;

/**
 * A song clip that opens after its first verse, or closes before its last, while the church is
 * still singing beside it.
 *
 * {@see SongLyricsOutsideSection} sees a song's lines sung beside the clip only where whisper
 * transcribed them, and it often does not: 1035 §4813's first verse was sung in unsectioned
 * time and never transcribed, and 1250 §3126's opening was sung straight on from the song
 * before. What *was* transcribed inside the clip still says where the clip enters and leaves
 * the song. The church rarely departs from the verse order recorded in the catalogue (operator,
 * 2026-09-24), so a clip whose first placed line belongs to a later verse has lost its opening,
 * and one whose last placed line belongs to an earlier verse has lost its ending.
 *
 * A line is placed in a verse by content-word pairs that no other verse of the song contains,
 * at least {@see self::MINIMUM_DISTINCT_PAIRS} of them and more than for any other verse; lines
 * every verse shares place nothing. Transcription loops and the leader speaking are skipped.
 * The sequence is the recorded verse order, or the document order without one, in which a
 * chorus appears once however often it is sung, so a clip may then close on any chorus. A clip
 * may also close on its first verse sung again after a later one.
 *
 * Whisper misses first and last verses too, so a later first line well inside the clip is room
 * for the verse it missed: only a placed line within {@see self::EDGE_ROOM_SECONDS} of the edge
 * is observed. It is a risk only when the {@see self::SOUND_SECONDS} beyond the edge are mostly
 * sustained sound, the song carrying on outside the clip; after a prayer or before a reading
 * the clip simply met a skipped or untranscribed verse.
 *
 * Measured 2026-09-24 over 1,177 historic song sections against fresh audio beyond each edge:
 * 53 edges placed a later opening or earlier closing within 20 s. With sustained sound beyond
 * the edge, 11 of 13 truncated openings raise (1225's recording itself starts mid-song) and
 * 5 of 6 truncated closings (1267's sound was quiet); 1 of 9 and 1 of 13 false alarms do. It
 * can say that a clip lost its opening, not where the song began, so it holds the clip rather
 * than moving its edge.
 */
final class SongOpeningAndClosing
{
    public const OPENING_RISK_KIND = 'song_opening_missing';

    public const CLOSING_RISK_KIND = 'song_closing_missing';

    private const MINIMUM_DISTINCT_PAIRS = 2;

    private const EDGE_ROOM_SECONDS = 20.0;

    private const SOUND_SECONDS = 10.0;

    private const MINIMUM_SUSTAINED_SHARE = 0.5;

    private const MAXIMUM_LINE_REPEATS = 3;

    /**
     * The leader speaking inside the clip: naming the song, calling the church to stand, sing,
     * pray or bow. A skipped line only loses one placement, so this is wider than
     * {@see SongLyricEdgeExtension}'s, which stops a walk.
     */
    private const LEADER_PATTERN = '/(going to sing|let\'?s (stand|sing|pray|bow)|let us (stand|sing|pray|bow)|shall we sing|we\'?ll sing|remain standing|sit down|hymn number|number \d|verse reads|first verse|next hymn|our (next|closing|opening) (hymn|song)|in prayer|word of prayer|reading|the title of|this hymn|this song|we\'?re going to|entitled)/i';

    public function __construct(
        private readonly OpenLpLyricsParser $lyricsParser,
    ) {}

    /**
     * @param  SustainedSound|null  $edgeSound  Judged on {@see SustainedSound::EDGE_WINDOW_BINS}
     * @return list<array{
     *     edge: 'opening'|'closing',
     *     verse: string|null,
     *     expected: string|null,
     *     position: int,
     *     of: int,
     *     line: string,
     *     at: float,
     *     room: float,
     *     sustained_share: float,
     *     risk: bool,
     *     detail: string
     * }>
     */
    public function observe(ServiceSection $section, ChurchServiceTranscript $transcript, ?SustainedSound $edgeSound): array
    {
        $song = $this->song($section);

        if ($song === null) {
            return [];
        }

        $sequence = $this->lyricsParser->sequence((string) $song->lyrics_xml, $song->verse_order);
        $verses = $this->distinctVerses($sequence['verses']);

        if (count($verses) < 2) {
            return [];
        }

        $start = (float) $section->start_time;
        $end = (float) $section->end_time;
        $placed = $this->placedLines($transcript, $start, $end, $verses);

        if ($placed === []) {
            return [];
        }

        $identity = fn (array $verse): string => $this->normalised($verse['text']);
        $positions = array_map($identity, $sequence['verses']);
        $firstExpected = $sequence['verses'][0];
        $lastExpected = $sequence['verses'][count($sequence['verses']) - 1];
        $first = $placed[0];
        $last = $placed[count($placed) - 1];
        $observations = [];

        if ($first['identity'] !== $identity($firstExpected) && $first['start'] - $start < self::EDGE_ROOM_SECONDS) {
            $share = $edgeSound?->share(max(0.0, $start - self::SOUND_SECONDS), $start - 0.01) ?? 0.0;
            $position = (int) array_search($first['identity'], $positions, true);
            $observations[] = $this->observation('opening', $first, $verses[$first['identity']], $firstExpected['key'], $position + 1, count($positions), $first['start'] - $start, $share);
        }

        $reprise = $last['identity'] === $identity($firstExpected)
            && array_filter($placed, static fn (array $line): bool => $line['identity'] !== $last['identity']) !== [];
        $chorus = ! $sequence['ordered'] && str_starts_with((string) $verses[$last['identity']], 'c');

        if ($last['identity'] !== $identity($lastExpected) && ! $reprise && ! $chorus && $end - $last['end'] < self::EDGE_ROOM_SECONDS) {
            $share = $edgeSound?->share($end, $end + self::SOUND_SECONDS - 0.01) ?? 0.0;
            $position = (int) array_key_last(array_filter($positions, static fn (string $text): bool => $text === $last['identity']));
            $observations[] = $this->observation('closing', $last, $verses[$last['identity']], $lastExpected['key'], $position + 1, count($positions), $end - $last['end'], $share);
        }

        return $observations;
    }

    /**
     * What {@see self::observe()} reads beyond the transcript and the sound, for the boundary
     * evidence fingerprint: an edited verse order or verse makes banked evidence stale.
     *
     * @return array{song_id: int|null, verses_sha256: string|null}
     */
    public function inputs(ServiceSection $section): array
    {
        $song = $this->song($section);

        return [
            'song_id' => $song?->id,
            'verses_sha256' => $song === null ? null : hash('sha256', $song->lyrics_xml."\n".$song->verse_order),
        ];
    }

    private function song(ServiceSection $section): ?Song
    {
        $songId = $section->resolvedSongId();

        return $songId === null ? null : Song::query()->find($songId);
    }

    /**
     * Each distinct verse text once, keyed by its normalised text, with its catalogue key.
     *
     * @param  list<array{key: string|null, text: string}>  $sequence
     * @return array<string, string|null>
     */
    private function distinctVerses(array $sequence): array
    {
        $verses = [];

        foreach ($sequence as $verse) {
            $verses[$this->normalised($verse['text'])] ??= $verse['key'];
        }

        return $verses;
    }

    /**
     * The transcript lines inside the clip that belong to exactly one verse, in time order.
     *
     * @param  array<string, string|null>  $verses
     * @return list<array{identity: string, start: float, end: float, text: string}>
     */
    private function placedLines(ChurchServiceTranscript $transcript, float $start, float $end, array $verses): array
    {
        $versePairs = [];

        foreach (array_keys($verses) as $identity) {
            $versePairs[$identity] = SongLyricsOutsideSection::wordPairs($identity);
        }

        $pairCounts = array_count_values(array_merge(...array_map(array_keys(...), array_values($versePairs))));
        $distinct = array_map(
            static fn (array $pairs): array => array_filter($pairs, static fn (string $pair): bool => $pairCounts[$pair] === 1, ARRAY_FILTER_USE_KEY),
            $versePairs,
        );

        $inside = array_values(array_filter($transcript->cues, static fn (array $cue): bool => $cue['start'] >= $start && $cue['start'] < $end));
        $repeats = array_count_values(array_map(fn (array $cue): string => $this->normalised($cue['text']), $inside));
        $placed = [];

        foreach ($inside as $cue) {
            if ($repeats[$this->normalised($cue['text'])] > self::MAXIMUM_LINE_REPEATS || preg_match(self::LEADER_PATTERN, $cue['text']) === 1) {
                continue;
            }

            $linePairs = SongLyricsOutsideSection::wordPairs($cue['text']);
            $scores = array_map(static fn (array $pairs): int => count(array_intersect_key($linePairs, $pairs)), $distinct);
            arsort($scores);
            $ranked = array_values($scores);

            if ($ranked[0] >= self::MINIMUM_DISTINCT_PAIRS && ($ranked[1] ?? 0) < $ranked[0]) {
                $placed[] = ['identity' => (string) array_key_first($scores), 'start' => (float) $cue['start'], 'end' => (float) $cue['end'], 'text' => $cue['text']];
            }
        }

        return $placed;
    }

    /**
     * @param  'opening'|'closing'  $edge
     * @param  array{identity: string, start: float, end: float, text: string}  $line
     * @param  int  $position  Where the placed verse falls in the sung sequence, from 1
     * @return array{edge: 'opening'|'closing', verse: string|null, expected: string|null, position: int, of: int, line: string, at: float, room: float, sustained_share: float, risk: bool, detail: string}
     */
    private function observation(string $edge, array $line, ?string $verse, ?string $expected, int $position, int $of, float $room, float $share): array
    {
        $risk = $share >= self::MINIMUM_SUSTAINED_SHARE;

        return [
            'edge' => $edge,
            'verse' => $verse,
            'expected' => $expected,
            'position' => $position,
            'of' => $of,
            'line' => $line['text'],
            'at' => $line['start'],
            'room' => round($room, 1),
            'sustained_share' => round($share, 2),
            'risk' => $risk,
            'detail' => sprintf(
                'The clip %s on %s, part %d of %d sung ("%s", %.1fs %s its %s), where the song %s %s%s.',
                $edge === 'opening' ? 'opens' : 'closes',
                $verse ?? 'an unlabelled verse',
                $position,
                $of,
                $line['text'],
                $room,
                $edge === 'opening' ? 'after' : 'before',
                $edge === 'opening' ? 'start' : 'end',
                $edge === 'opening' ? 'begins with' : 'ends with',
                $expected ?? 'another verse',
                $risk
                    ? sprintf(', and the sound %s it is sustained, so the church was still singing', $edge === 'opening' ? 'before' : 'after')
                    : sprintf('; the sound %s it pauses like speech, so the missing verse was skipped or not transcribed', $edge === 'opening' ? 'before' : 'after'),
            ),
        ];
    }

    private function normalised(string $text): string
    {
        return trim((string) preg_replace('/[^a-z]+/', ' ', mb_strtolower($text)));
    }
}
