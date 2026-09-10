<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ServiceSectionType;

/**
 * One section's verdict against the media it is timed on.
 *
 * The measured duration and the recorded end are both carried because the
 * disposition alone cannot be audited: "past the source end" is only a finding
 * if a reader can see which two numbers disagreed and by how much.
 */
final readonly class SectionSourceBoundsEntry
{
    public function __construct(
        public int $sectionId,
        public int $logId,
        public string $processingId,
        public ServiceSectionType $sectionType,
        public string $publicationStatus,
        public float $startTime,
        public float $endTime,
        public string $disposition,
        /** The detector's original end, before any clamp this screen applied. */
        public float $recordedEnd,
        public ?float $measuredDuration = null,
        /** Cue ends past the measured duration in this run's stored transcript. */
        public ?int $cuesPastSourceEnd = null,
        public ?string $reason = null,
    ) {}

    /**
     * Seconds of this section that no media exists for.
     *
     * Measured from the recorded claim rather than the current bound, so a
     * section this screen has already clamped still reports the overrun that
     * earned the clamp instead of reporting none.
     */
    public function overrunSeconds(): float
    {
        if ($this->measuredDuration === null) {
            return 0.0;
        }

        return max(0.0, $this->recordedEnd - $this->measuredDuration);
    }

    /** The end this section should carry, given the media that exists. */
    public function resolvedEnd(): ?float
    {
        if ($this->measuredDuration === null) {
            return null;
        }

        return min($this->recordedEnd, $this->measuredDuration);
    }

    public function isRepairable(): bool
    {
        return $this->disposition === SectionSourceBoundsScreen::DispositionPastSourceEnd
            || $this->disposition === SectionSourceBoundsScreen::DispositionRestorable;
    }
}
