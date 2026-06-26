<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use App\Support\SermonContentFormatter;

/**
 * Formats a sermon's date and duration for display.
 *
 * Extracted from SermonViewPresenter so the date/duration cluster lives behind a
 * single-responsibility collaborator. Duration formatting delegates to the pure
 * SermonContentFormatter; date formatting is memoized by the sermon's date
 * timestamp so that multiple sermons sharing a date in a listing format it once.
 *
 * The presenter owns one instance per request and delegates its public
 * date/duration accessors here, so this collaborator is the single home for the
 * date memo (cleared via clearCache() when the presenter clears its caches).
 */
class SermonDateFormatter
{
    /**
     * Memoized date strings keyed by the sermon's date timestamp.
     *
     * @var array<int, array{human: string, iso: string, short: string}>
     */
    private array $memoizedDates = [];

    /**
     * Get the human-friendly formatted duration of the sermon (e.g. "1h 30m").
     */
    public function formattedDuration(Sermon $sermon): ?string
    {
        return SermonContentFormatter::humanDuration($this->durationInSeconds($sermon));
    }

    /**
     * Get the ISO 8601 duration string (e.g. PT45M) for the sermon.
     */
    public function durationIso8601(Sermon $sermon): ?string
    {
        return SermonContentFormatter::iso8601Duration($this->durationInSeconds($sermon));
    }

    /**
     * Get various formatted date strings for the sermon.
     *
     * Performance Optimization: Memoizes date formatting results by timestamp
     * to avoid redundant object calls and string formatting across multiple
     * sermons sharing the same date in a listing. Returns human-friendly,
     * ISO 8601, and short display formats.
     *
     * @return array{human: string, iso: string, short: string}
     */
    public function formattedDates(Sermon $sermon): array
    {
        $timestamp = $sermon->date->getTimestamp();

        return $this->memoizedDates[$timestamp] ??= [
            'human' => $sermon->date->format('F j, Y'),
            'iso' => $sermon->date->toDateString(),
            'short' => $sermon->date->format('j F Y'),
        ];
    }

    /**
     * Get the human-friendly date of the sermon.
     */
    public function humanDate(Sermon $sermon): string
    {
        return $this->formattedDates($sermon)['human'];
    }

    /**
     * Clear the memoized date cache.
     *
     * Called by the presenter's clearInternalCaches() so a single reset covers
     * every cache the presenter and its collaborators hold.
     */
    public function clearCache(): void
    {
        $this->memoizedDates = [];
    }

    /**
     * Normalize the float-cast `duration` column to an integer number of seconds.
     */
    private function durationInSeconds(Sermon $sermon): ?int
    {
        return $sermon->duration === null ? null : (int) $sermon->duration;
    }
}
