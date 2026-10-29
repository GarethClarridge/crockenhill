<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A later part of a sermon video, named by the section it opens on. A sermon video's segments carry
 * no section ids, and recomposition or a dead-feed split can renumber them, so a ruling on a part's
 * start binds to that section rather than to a segment index (1208, 1235 and 1250's joins after a
 * song, plan §4.1a). A start ruling without a part means the video's opening.
 */
final class SermonVideoPart
{
    /**
     * The index of the first segment overlapping the part's section. Fails when none does, or when
     * that segment opens the video: the opening's own ruling judges it, never a later part's.
     *
     * @param  list<array{start_time: float, end_time: float}>  $segments
     * @param  array{id: int|string, start_time: float|string, end_time: float|string}  $section
     */
    public static function segmentIndex(array $segments, array $section): int
    {
        foreach ($segments as $index => $segment) {
            if ($segment['start_time'] < (float) $section['end_time'] && $segment['end_time'] > (float) $section['start_time']) {
                if ($index === 0) {
                    throw new RuntimeException("§{$section['id']} opens the sermon video, so the video's opening ruling governs it, not this later part's");
                }

                return $index;
            }
        }

        throw new RuntimeException("no segment of the sermon video overlaps §{$section['id']}");
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
