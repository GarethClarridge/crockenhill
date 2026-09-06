<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

/**
 * One run's transcript-repair verdict, and the evidence behind it.
 *
 * A dry run reports these. The lengths and the disk are part of the verdict, not
 * decoration: a repair that removes nothing has misread the spans, and a repair
 * written to the wrong disk would leave the sermon pointing at the old text
 * while reporting success.
 */
final readonly class SermonTranscriptSpanRepairEntry
{
    public function __construct(
        public string $processingId,
        public int $logId,
        public ?int $sermonId,
        public int $spanCount,
        public string $disposition,
        public ?string $reason = null,
        public ?string $disk = null,
        public ?string $path = null,
        public ?int $currentLength = null,
        public ?int $repairedLength = null,
        public ?string $repairedText = null,
    ) {}

    public function isRepairable(): bool
    {
        return $this->disposition === HistoricSermonTranscriptSpanRepair::DISPOSITION_REPAIRABLE;
    }

    /**
     * Characters the repair drops, or null when there is nothing to compare.
     */
    public function removedLength(): ?int
    {
        if ($this->currentLength === null || $this->repairedLength === null) {
            return null;
        }

        return $this->currentLength - $this->repairedLength;
    }

    public function with(string $disposition, ?string $reason = null): self
    {
        return new self(
            processingId: $this->processingId,
            logId: $this->logId,
            sermonId: $this->sermonId,
            spanCount: $this->spanCount,
            disposition: $disposition,
            reason: $reason,
            disk: $this->disk,
            path: $this->path,
            currentLength: $this->currentLength,
            repairedLength: $this->repairedLength,
            repairedText: $this->repairedText,
        );
    }
}
