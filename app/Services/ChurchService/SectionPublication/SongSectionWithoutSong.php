<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\ChurchServiceTranscript;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\Song\SongLyricIdentityCheck;

/**
 * A short song section with none of its bound song in it.
 *
 * The §4.1b tail inspections found song video 74 (§622) to be the leader announcing "The Lord's
 * My Shepherd" and nothing more, and video 83 (§678) to be the doxology sung as a second copy of
 * the hymn. Both sit above the 15 s micro floor, so the micro-section rule never reached them.
 *
 * Measured 2026-09-16 (`sungpass-20260916-measure.json`, row d): 42 song sections run under
 * {@see self::MAXIMUM_SECONDS}, and 17 share almost nothing with their bound song's lyrics. The
 * pairs a leader's announcement shares with the song's *title* are not singing, so they are set
 * aside before counting; without that, "sing our final hymn O Church Arise" (§2851) passes as
 * the hymn.
 *
 * The doxology (§678, §3284) is **not** caught. Its closing line is the last verse of its bound
 * Old Hundredth, so it shares the word pairs this rule counts; whether the doxology is a
 * catalogue item of its own is an operator decision. It stays held by the duration gate.
 *
 * Each finding is a boundary-evidence risk, so it holds the clip for review on its own; below
 * 90 s the duration gate holds it too, and this adds the diagnosis. `basis` tells a reviewer
 * which it is: an `announcement` names the bound title, a `fragment` does not. Sections of a
 * minute or more, and songs with no catalogue lyrics, are not judged.
 */
final class SongSectionWithoutSong
{
    public const RISK_KIND = 'song_section_without_song';

    private const MAXIMUM_SECONDS = 60.0;

    /** One shared pair is a stock phrase ("praise him"); the sung sections of row d share 2 or more. */
    private const MINIMUM_WORD_PAIRS = 2;

    /** An announcement names most of the title's content words; §622 names all of them. */
    private const ANNOUNCEMENT_TITLE_OVERLAP = 0.5;

    /**
     * @return array{basis: 'announcement'|'fragment', seconds: float, word_pairs: int, risk: bool, detail: string}|null
     */
    public function observe(ServiceSection $section, ChurchServiceTranscript $transcript): ?array
    {
        $seconds = (float) $section->end_time - (float) $section->start_time;
        $songId = $section->resolvedSongId();

        if ($seconds >= self::MAXIMUM_SECONDS || $songId === null) {
            return null;
        }

        $song = Song::query()->find($songId, ['id', 'title', 'lyrics_plain']);

        if (! $song instanceof Song || trim((string) $song->lyrics_plain) === '') {
            return null;
        }

        $titleWords = SongLyricIdentityCheck::contentWords((string) preg_replace('/\s*#\w+$/', '', (string) $song->title));
        $spokenWords = SongLyricIdentityCheck::contentWords($transcript->sliceText((float) $section->start_time, (float) $section->end_time));

        $sharedPairs = array_diff_key(
            array_intersect_key(
                SongLyricIdentityCheck::wordPairs($spokenWords),
                SongLyricIdentityCheck::wordPairs(SongLyricIdentityCheck::contentWords((string) $song->lyrics_plain)),
            ),
            SongLyricIdentityCheck::wordPairs($titleWords),
        );

        $titleOverlap = $titleWords === []
            ? 0.0
            : count(array_intersect(array_unique($titleWords), $spokenWords)) / count(array_unique($titleWords));
        $basis = $titleOverlap >= self::ANNOUNCEMENT_TITLE_OVERLAP ? 'announcement' : 'fragment';
        $risk = count($sharedPairs) < self::MINIMUM_WORD_PAIRS;

        return [
            'basis' => $basis,
            'seconds' => round($seconds, 1),
            'word_pairs' => count($sharedPairs),
            'risk' => $risk,
            'detail' => $risk
                ? sprintf(
                    'A %.1fs song section shares %d word pair(s) with its bound song beyond the title, so it reads as %s rather than the song.',
                    $seconds,
                    count($sharedPairs),
                    $basis === 'announcement' ? 'the song being announced' : 'something else',
                )
                : sprintf('A %.1fs song section shares %d word pairs with its bound song.', $seconds, count($sharedPairs)),
        ];
    }
}
