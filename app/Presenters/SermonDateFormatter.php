<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use App\Support\SermonContentFormatter;

/**
 * Formats a sermon's date and duration for display.
 *
 * Duration formatting delegates to the pure SermonContentFormatter.
 */
class SermonDateFormatter
{
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
     * @return array{human: string, iso: string, short: string}
     */
    public function formattedDates(Sermon $sermon): array
    {
        return [
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
     * Normalize the float-cast `duration` column to an integer number of seconds.
     */
    private function durationInSeconds(Sermon $sermon): ?int
    {
        return $sermon->duration === null ? null : (int) $sermon->duration;
    }
}
