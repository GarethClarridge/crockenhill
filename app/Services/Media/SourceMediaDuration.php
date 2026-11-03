<?php

declare(strict_types=1);

namespace App\Services\Media;

use Throwable;

/**
 * How long a source recording runs: its container's duration, or, when the container declares
 * none, the duration its video packets add up to. A YouTube `.webm` written without an index is
 * the common case; read as `(float)` of the missing value it became 0 s, which clamped re-import
 * 18432's every edge window to nothing (11-02 round). Null, never 0 or a guess, when neither is
 * readable, so a clamp that falls back on an unknown duration does.
 */
class SourceMediaDuration
{
    public function __construct(
        private readonly ExtractedMediaDurationProbe $header,
        private readonly PacketCountedMediaDuration $packets,
    ) {}

    public function seconds(string $absolutePath): ?float
    {
        try {
            return $this->header->durationOf($absolutePath);
        } catch (Throwable) {
            return $this->packets->seconds($absolutePath);
        }
    }
}
