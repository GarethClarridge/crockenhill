<?php

declare(strict_types=1);

namespace App\Data;

/**
 * How much of a whole recording has no usable picture.
 *
 * "Dead" is measured, not inferred from appearance: frozen time is picture that
 * did not change at all, black time is picture that was (almost) black. A black
 * picture is also a frozen one, so the two overlap; `deadSeconds` is the union
 * of their intervals, never their sum.
 */
final readonly class VideoDeadPictureCoverage
{
    /**
     * @param  list<array{float, float}>  $deadIntervals  Merged, ordered [start, end] stretches of dead picture.
     */
    public function __construct(
        public float $durationSeconds,
        public float $deadSeconds,
        public float $freezeSeconds,
        public float $blackSeconds,
        public array $deadIntervals,
    ) {}

    /**
     * The share of the recording whose picture is usable, from 0 to 1.
     */
    public function usableShare(): float
    {
        if ($this->durationSeconds <= 0.0) {
            return 0.0;
        }

        return max(0.0, 1.0 - ($this->deadSeconds / $this->durationSeconds));
    }
}
