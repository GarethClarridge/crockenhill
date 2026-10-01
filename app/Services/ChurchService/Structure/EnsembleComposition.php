<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;

final readonly class EnsembleComposition
{
    /**
     * @param  list<array<string, mixed>>  $disputes
     * @param  list<array<string, mixed>>  $provenance
     * @param  list<array<string, mixed>>  $majorityDecisions  Disagreements a three-to-one vote settled; shown
     *                                                         for skimming and still answerable, never held
     */
    public function __construct(
        public ServiceStructure $structure,
        public array $disputes,
        public array $provenance,
        public bool $degraded,
        public bool $refused,
        public int $validVotes,
        public bool $degradedReviewed = false,
        public array $majorityDecisions = [],
    ) {}

    public function requiresReview(): bool
    {
        return $this->refused || ($this->degraded && ! $this->degradedReviewed) || $this->disputes !== [];
    }
}
