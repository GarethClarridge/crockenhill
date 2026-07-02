<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;

/**
 * The outcome of running a detected structure through the deterministic gate.
 *
 * Hard failures mean the structure must not reach persistence — the run routes
 * to manual review instead. Soft failures are carried as per-section review
 * flags on the (annotated) structure and as unmatched-item ids here.
 */
final readonly class ValidationResult
{
    /**
     * @param  ServiceStructure  $structure  The input structure annotated with soft review flags
     * @param  list<array{code: string, message: string}>  $hardFailures
     * @param  list<int>  $unmatchedOosItemIds  OoS items no section claimed (soft)
     */
    public function __construct(
        public ServiceStructure $structure,
        public array $hardFailures = [],
        public array $unmatchedOosItemIds = [],
    ) {}

    public function passed(): bool
    {
        return $this->hardFailures === [];
    }

    /**
     * @return list<string>
     */
    public function failureCodes(): array
    {
        return array_values(array_unique(array_column($this->hardFailures, 'code')));
    }

    public function failureSummary(): string
    {
        return implode(' ', array_column($this->hardFailures, 'message'));
    }
}
