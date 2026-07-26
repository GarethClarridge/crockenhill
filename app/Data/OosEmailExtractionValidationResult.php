<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosEmailExtractionValidationResult
{
    /**
     * @param  list<string>  $globalReasons
     * @param  array<int, list<string>>  $planReasons
     */
    public function __construct(
        public array $globalReasons = [],
        public array $planReasons = [],
    ) {}

    /**
     * @return list<string>
     */
    public function allReasons(): array
    {
        return array_values(array_unique(array_merge(
            $this->globalReasons,
            ...array_values($this->planReasons),
        )));
    }

    /**
     * @return list<string>
     */
    public function reasonsForPlan(int $planIndex): array
    {
        return array_values(array_unique(array_merge(
            $this->globalReasons,
            $this->planReasons[$planIndex] ?? [],
        )));
    }

    public function isValid(): bool
    {
        return $this->allReasons() === [];
    }

    public function reasonCount(): int
    {
        return count($this->allReasons());
    }
}
