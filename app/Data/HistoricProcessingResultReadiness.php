<?php

declare(strict_types=1);

namespace App\Data;

readonly class HistoricProcessingResultReadiness
{
    /** @param list<string> $reasons */
    public function __construct(
        public bool $ready,
        public array $reasons,
        public ?string $logicalHash,
    ) {}
}
