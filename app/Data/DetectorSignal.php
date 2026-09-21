<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\DetectorSurface;
use App\Support\DetectorCatalogue;

/**
 * One finding a promoted detector recorded, in the single shape the evaluation
 * harness scores regardless of which surface produced it.
 *
 * `detectorId` is nullable on purpose. A stored flag that no catalogue entry
 * claims is not an error to swallow — it is either a flag whose raise site was
 * deleted without retiring it, or a detector that shipped without an evaluation
 * entry. Both are exactly the coverage gap §4.3a exists to close, so the adapter
 * carries the finding through with a null id and lets the report name it, rather
 * than dropping it and reporting clean coverage of a set it quietly narrowed.
 *
 * `held` records whether this finding actually withheld anything. A detector
 * that fires without holding has contained nothing, so precision and review
 * burden have to be read against it.
 *
 * @phpstan-type DetectorSignalShape array{
 *     detector_id: string|null,
 *     surface: string,
 *     signal: string,
 *     run_id: int,
 *     section_id: int|null,
 *     start: float|null,
 *     end: float|null,
 *     held: bool,
 * }
 */
final readonly class DetectorSignal
{
    public function __construct(
        public ?string $detectorId,
        public DetectorSurface $surface,
        public string $signal,
        public int $runId,
        public ?int $sectionId = null,
        public ?float $start = null,
        public ?float $end = null,
        public bool $held = false,
    ) {}

    /**
     * Build a signal, resolving its detector from the catalogue.
     */
    public static function forStoredSignal(
        DetectorSurface $surface,
        string $signal,
        int $runId,
        ?int $sectionId = null,
        ?float $start = null,
        ?float $end = null,
        bool $held = false,
    ): self {
        return new self(
            detectorId: DetectorCatalogue::forSignal($surface, $signal)?->id,
            surface: $surface,
            signal: $signal,
            runId: $runId,
            sectionId: $sectionId,
            start: $start,
            end: $end,
            held: $held,
        );
    }

    /**
     * Whether the catalogue knows which detector emitted this.
     *
     * False is a finding about the catalogue, not about the service.
     */
    public function isCatalogued(): bool
    {
        return $this->detectorId !== null;
    }

    /**
     * @return DetectorSignalShape
     */
    public function toArray(): array
    {
        return [
            'detector_id' => $this->detectorId,
            'surface' => $this->surface->value,
            'signal' => $this->signal,
            'run_id' => $this->runId,
            'section_id' => $this->sectionId,
            'start' => $this->start,
            'end' => $this->end,
            'held' => $this->held,
        ];
    }
}
