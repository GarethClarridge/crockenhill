<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterval;

/**
 * Pure, dependency-free formatting of sermon scalar content.
 *
 * Extracted from SermonViewPresenter so duration and outline formatting can be
 * unit-tested in isolation, without the model, storage faking, or the presenter's
 * request-level memoization. Every method is a deterministic function of its
 * primitive inputs, so it needs no caching of its own.
 */
final class SermonContentFormatter
{
    /**
     * Format a duration in seconds as a human-friendly string (e.g. "1h 30m" or "45m").
     *
     * Returns null for missing or non-positive durations.
     */
    public static function humanDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0
            ? "{$hours}h {$minutes}m"
            : "{$minutes}m";
    }

    /**
     * Format a duration in seconds as an ISO 8601 duration string (e.g. "PT45M").
     *
     * Returns null for missing or non-positive durations.
     */
    public static function iso8601Duration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return CarbonInterval::seconds($seconds)->cascade()->spec();
    }

    /**
     * Render structured sermon points as a numbered plain-text outline.
     *
     * Each point may be a scalar (the point text) or an array shaped like
     * `['point' => string, 'sub_points' => array<int, scalar>]`. Empty points and
     * sub-points are skipped; an untitled point with sub-points is labelled
     * "(Untitled point)". Returns null when there is nothing to render.
     *
     * @param  mixed  $points
     */
    public static function plainTextOutline($points): ?string
    {
        if (! is_array($points) || $points === []) {
            return null;
        }

        $outline = '';
        $counter = 1;

        foreach ($points as $pointItem) {
            $mainText = '';
            $subLines = [];

            if (is_array($pointItem)) {
                $mainText = (isset($pointItem['point']) && is_scalar($pointItem['point'])) ? trim((string) $pointItem['point']) : '';
                $subPoints = (isset($pointItem['sub_points']) && is_array($pointItem['sub_points'])) ? $pointItem['sub_points'] : [];

                foreach ($subPoints as $subPoint) {
                    if (is_scalar($subPoint) && filled($subPoint)) {
                        $subLines[] = '   - '.trim((string) $subPoint);
                    }
                }
            } elseif (is_scalar($pointItem)) {
                $mainText = trim((string) $pointItem);
            }

            if ($mainText !== '' || count($subLines) > 0) {
                $outline .= "{$counter}. ".($mainText !== '' ? $mainText : '(Untitled point)')."\n";

                foreach ($subLines as $subLine) {
                    $outline .= "{$subLine}\n";
                }

                $counter++;
            }
        }

        return trim($outline) ?: null;
    }
}
