<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A part of a sermon video other than its opening or close, named by a section: a later part's start
 * by the section it opens on, an earlier part's end by the section it ends on. A sermon video's
 * segments carry no section ids, and recomposition or a dead-feed split can renumber them, so a
 * ruling on an inner join binds to that section rather than to a segment index (1208, 1235 and
 * 1250's starts after a song; 928's reading end, plan §4.1a). A sermon ruling without a part means
 * the video's opening or close.
 */
final class SermonVideoPart
{
    /**
     * The index of the segment the part's edge is cut on: for a start, the first segment overlapping
     * the section; for an end, the last. Fails when none does, or when that segment opens (or closes)
     * the video: the opening's (or close's) own ruling judges it, never an inner join's.
     *
     * @param  list<array{start_time: float, end_time: float}>  $segments
     * @param  array{id: int|string, start_time: float|string, end_time: float|string}  $section
     */
    public static function segmentIndex(array $segments, array $section, string $edge = 'start'): int
    {
        $overlapping = array_keys(array_filter($segments, static fn (array $segment): bool => $segment['start_time'] < (float) $section['end_time']
            && $segment['end_time'] > (float) $section['start_time']));
        if ($overlapping === []) {
            throw new RuntimeException("no segment of the sermon video overlaps §{$section['id']}");
        }
        if ($edge === 'end') {
            $index = end($overlapping);
            if ($index === array_key_last($segments)) {
                throw new RuntimeException("§{$section['id']} closes the sermon video, so the video's closing ruling governs it, not this earlier part's");
            }

            return $index;
        }
        if ($overlapping[0] === 0) {
            throw new RuntimeException("§{$section['id']} opens the sermon video, so the video's opening ruling governs it, not this later part's");
        }

        return $overlapping[0];
    }

    /**
     * @param  list<array{id: int|string, start_time: float|string, end_time: float|string}>  $sections
     * @return array{id: int|string, start_time: float|string, end_time: float|string}
     */
    public static function section(array $sections, int $sectionId): array
    {
        foreach ($sections as $section) {
            if ((int) $section['id'] === $sectionId) {
                return $section;
            }
        }

        throw new RuntimeException("the capture holds no §{$sectionId} to find the sermon video's later part by");
    }
}
