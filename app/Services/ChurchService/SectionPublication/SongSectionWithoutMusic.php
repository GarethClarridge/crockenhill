<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Models\ServiceSection;
use App\Services\Media\Audio\AudioTimeline;

/**
 * A song clip the classifier hears no music in anywhere.
 *
 * 992 §163678's recording ends before "Restore, O Lord" is sung: the clip is the leader reading
 * its words and "Let's stand and sing this one through together." {@see SongSectionWithoutSong}
 * passes it, since read words share the song's lyrics. A song is sung or wholly instrumental
 * (register D6), but unaccompanied singing also sounds like no music to the classifier (1252's
 * "Happy Birthday", 1352's unidentified singing), and word timings cannot tell speech from
 * singing (10-28), so the clip goes to a reviewer, who hears which it is.
 *
 * Measured 2026-10-31 (`song-sections-without-music-20261031/`): of 1,233 song sections with a
 * timeline, 17 have no window reaching {@see AudioTimeline::MUSIC_CUTOFF} (all under 0.19); the
 * next have music in at least one window (0.40 and up). Runs without a timeline are not judged.
 */
final class SongSectionWithoutMusic
{
    public const RISK_KIND = 'song_section_without_music';

    /**
     * @return array{risk: bool, highest_music: float, seconds: float, detail: string}|null
     */
    public function observe(ServiceSection $section, ?AudioTimeline $timeline): ?array
    {
        $start = (float) $section->start_time;
        $end = (float) $section->end_time;

        if ($timeline === null || $end <= $start || $timeline->windowAt($start) === null) {
            return null;
        }

        $music = array_column(array_filter(
            $timeline->windows,
            static fn (array $window): bool => $window['end'] > $start && $window['start'] < $end,
        ), 'music');
        $highest = $music === [] ? 0.0 : max($music);
        $seconds = round($end - $start, 1);
        $risk = $highest < AudioTimeline::MUSIC_CUTOFF;

        return [
            'risk' => $risk,
            'highest_music' => round($highest, 3),
            'seconds' => $seconds,
            'detail' => $risk
                ? sprintf('No music is heard anywhere in this %.1fs song clip (highest score %.2f): it may be sung unaccompanied, or its words spoken rather than sung.', $seconds, $highest)
                : sprintf('Music is heard in this %.1fs song clip (highest score %.2f).', $seconds, $highest),
        ];
    }
}
