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
 *     sermon_id: int|null,
 *     start: float|null,
 *     end: float|null,
 *     held: bool,
 *     context: array<string, mixed>,
 * }
 */
final readonly class DetectorSignal
{
    /**
     * @param  array<string, mixed>  $context  Facts belonging to one surface only.
     */
    public function __construct(
        public ?string $detectorId,
        public DetectorSurface $surface,
        public string $signal,
        public int $runId,
        public ?int $sectionId = null,
        public ?int $sermonId = null,
        public ?float $start = null,
        public ?float $end = null,
        public bool $held = false,
        public array $context = [],
    ) {}

    /**
     * Build a signal, resolving its detector from the catalogue.
     *
     * `$context` carries facts that belong to one surface only — a video
     * verdict's visibility override, say — so the shared shape stays the same
     * for every adapter.
     *
     * @param  array<string, mixed>  $context
     */
    public static function forStoredSignal(
        DetectorSurface $surface,
        string $signal,
        int $runId,
        ?int $sectionId = null,
        ?int $sermonId = null,
        ?float $start = null,
        ?float $end = null,
        bool $held = false,
        array $context = [],
    ): self {
        return new self(
            detectorId: DetectorCatalogue::forSignal($surface, $signal)?->id,
            surface: $surface,
            signal: $signal,
            runId: $runId,
            sectionId: $sectionId,
            sermonId: $sermonId,
            start: $start,
            end: $end,
            held: $held,
            context: $context,
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
            'sermon_id' => $this->sermonId,
            'start' => $this->start,
            'end' => $this->end,
            'held' => $this->held,
            'context' => $this->context,
        ];
    }
}
