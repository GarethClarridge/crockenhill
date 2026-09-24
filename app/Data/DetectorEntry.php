<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\DetectorSeverity;
use App\Enums\DetectorStatus;
use App\Enums\DetectorSurface;
use App\Enums\DetectorUnit;
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
 * - A **decided-not-to-detect** or **fixed-at-source** entry with no recorded
 *   grounds is indistinguishable from an unbuilt one, which is precisely the
 *   distinction {@see DetectorStatus} exists to keep.
 * - An **emitting** entry with no signals or no surface can never match anything
 *   an adapter reads, so it would sit in the catalogue looking covered while
 *   silently matching nothing.
 * - A **non-emitting** entry that declares signals or a surface claims a
 *   detector where the plan records a fix, a ruling or an open question. That
 *   direction matters as much as the other: since 2026-09-21 the catalogue holds
 *   one entry per class-table row, so most entries are not detectors at all, and
 *   nothing but this guard stops one drifting into looking like one.
 *
 * Each is raised at construction rather than discovered when a report comes back
 * mysteriously empty.
 *
 * @phpstan-type DetectorEntryShape array{
 *     id: string,
 *     surface: string|null,
 *     signals: list<string>,
 *     status: string,
 *     severity: string,
 *     unit: string,
 *     summary: string,
 *     owning_class: string|null,
 *     regression_cases: list<string>,
 *     decision: string|null,
 *     contained_by_flag: bool,
 * }
 */
final readonly class DetectorEntry
{
    /**
     * @param  list<string>  $signals  Reasons this detector emits on its surface.
     * @param  list<string>  $regressionCases  Plan case references, e.g. `run 1340`, `§3992`.
     * @param  bool  $containedByFlag  Whether a recorded flag, not a hold, is this class's correct
     *                                 outcome — an operator ruling, such as releasing a partly dead
     *                                 video whole with a "has video issues" flag.
     */
    public function __construct(
        public string $id,
        public ?DetectorSurface $surface,
        public array $signals,
        public DetectorStatus $status,
        public DetectorSeverity $severity,
        public DetectorUnit $unit,
        public string $summary,
        public ?string $owningClass = null,
        public array $regressionCases = [],
        public ?string $decision = null,
        public bool $containedByFlag = false,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('A detector entry needs an id.');
        }

        if ($this->status->emitsSignals()) {
            if ($this->signals === []) {
                throw new InvalidArgumentException(
                    "Detector [{$this->id}] declares no signals, so no adapter could ever match it."
                );
            }

            if ($this->surface === null) {
                throw new InvalidArgumentException(
                    "Detector [{$this->id}] emits signals but names no surface to emit them on."
                );
            }
        } else {
            if ($this->signals !== []) {
                throw new InvalidArgumentException(
                    "Entry [{$this->id}] is {$this->status->value} but declares signals. "
                    .'A class with no detector emits nothing; its evidence is a regression test.'
                );
            }

            if ($this->surface !== null) {
                throw new InvalidArgumentException(
                    "Entry [{$this->id}] is {$this->status->value} but names a surface it never emits on."
                );
            }
        }

        if ($this->status->requiresOwningClass() && $this->owningClass === null) {
            throw new InvalidArgumentException(
                "Promoted detector [{$this->id}] must name the class that emits it."
            );
        }

        if ($this->status->requiresDecision() && $this->decision === null) {
            throw new InvalidArgumentException(
                "Entry [{$this->id}] is {$this->status->value} but records no grounds for having no detector."
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
     * An unbuilt class has nothing to measure yet, and an S4 class
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
            'surface' => $this->surface?->value,
            'signals' => $this->signals,
            'status' => $this->status->value,
            'severity' => $this->severity->value,
            'unit' => $this->unit->value,
            'summary' => $this->summary,
            'owning_class' => $this->owningClass,
            'regression_cases' => $this->regressionCases,
            'decision' => $this->decision,
            'contained_by_flag' => $this->containedByFlag,
        ];
    }
}
