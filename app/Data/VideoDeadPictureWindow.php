<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One sampled window of a recording, with the time its picture was dead.
 *
 * "Dead" is measured, not inferred from appearance: `freezeSeconds` is time the
 * picture did not change at all, `blackSeconds` is time it was (almost) black.
 * A window can be both, because a black picture is also a frozen one.
 */
final readonly class VideoDeadPictureWindow
{
    public function __construct(
        public float $start,
        public float $length,
        public float $freezeSeconds,
        public float $blackSeconds,
    ) {}

    /**
     * The longer of the two dead measures: black time is a subset of freeze time
     * on a truly black picture, so summing them would double-count it.
     */
    public function deadSeconds(): float
    {
        return max($this->freezeSeconds, $this->blackSeconds);
    }

    /**
     * Whether this window reads as dead, at the given share of its own length.
     *
     * The share matters because windows at the end of a recording are clipped
     * to whatever time is left.
     */
    public function isDead(float $minimumDeadRatio): bool
    {
        if ($this->length <= 0.0) {
            return false;
        }

        return $this->deadSeconds() >= $this->length * $minimumDeadRatio;
    }

    /**
     * @return array{start: float, length: float, freeze_seconds: float, black_seconds: float}
     */
    public function toArray(): array
    {
        return [
            'start' => round($this->start, 3),
            'length' => round($this->length, 3),
            'freeze_seconds' => round($this->freezeSeconds, 3),
            'black_seconds' => round($this->blackSeconds, 3),
        ];
    }
}
