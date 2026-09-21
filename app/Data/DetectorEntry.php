<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\DetectorSeverity;
use App\Enums\DetectorStatus;
use App\Enums\DetectorSurface;
use App\Support\DetectorCatalogue;
use InvalidArgumentException;

/**
 * One row of §4.3a's defect-class table, as data the evaluation harness can act
 * on rather than prose a reader has to interpret.
 *
 * The constructor refuses entries that would be undetectable mistakes later:
 *
 * - A **promoted** detector with no owning class cannot be re-run over the
 *   corpus, so the catalogue would claim coverage the harness cannot deliver.
 * - A **decided-not-to-detect** entry with no decision text is indistinguishable
 *   from an unbuilt one, which is precisely the distinction
 *   {@see DetectorStatus} exists to keep.
 * - An entry with no signals can never match anything an adapter reads, so it
 *   would sit in the catalogue looking covered while silently matching nothing.
 *
 * Each is raised at construction rather than discovered when a report comes back
 * mysteriously empty.
 *
 * @phpstan-type DetectorEntryShape array{
 *     id: string,
 *     surface: string,
 *     signals: list<string>,
 *     status: string,
 *     severity: string,
 *     summary: string,
 *     owning_class: string|null,
 *     regression_cases: list<string>,
 *     decision: string|null,
 * }
 */
final readonly class DetectorEntry
{
    /**
     * @param  list<string>  $signals  Reasons this detector emits on its surface.
     * @param  list<string>  $regressionCases  Plan case references, e.g. `run 1340`, `§3992`.
     */
    public function __construct(
        public string $id,
        public DetectorSurface $surface,
        public array $signals,
        public DetectorStatus $status,
        public DetectorSeverity $severity,
        public string $summary,
        public ?string $owningClass = null,
        public array $regressionCases = [],
        public ?string $decision = null,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('A detector entry needs an id.');
        }

        if ($this->signals === []) {
            throw new InvalidArgumentException(
                "Detector [{$this->id}] declares no signals, so no adapter could ever match it."
            );
        }

        if ($this->status->requiresOwningClass() && $this->owningClass === null) {
            throw new InvalidArgumentException(
                "Promoted detector [{$this->id}] must name the class that emits it."
            );
        }

        if ($this->status === DetectorStatus::DecidedNotToDetect && $this->decision === null) {
            throw new InvalidArgumentException(
                "Detector [{$this->id}] is decided-not-to-detect but records no decision."
            );
        }
    }

    public function emits(string $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    /**
     * Whether this entry is one the harness must be able to score.
     *
     * A prototype or unbuilt class has nothing to measure yet, and an S4 class
     * is reporting-only, so neither is a gap in {@see DetectorCatalogue}'s
     * evaluation coverage.
     */
    public function isEvaluable(): bool
    {
        return $this->status === DetectorStatus::Promoted && $this->severity->isScored();
    }

    /**
     * @return DetectorEntryShape
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'surface' => $this->surface->value,
            'signals' => $this->signals,
            'status' => $this->status->value,
            'severity' => $this->severity->value,
            'summary' => $this->summary,
            'owning_class' => $this->owningClass,
            'regression_cases' => $this->regressionCases,
            'decision' => $this->decision,
        ];
    }
}
