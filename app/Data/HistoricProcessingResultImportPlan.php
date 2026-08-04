<?php

declare(strict_types=1);

namespace App\Data;

readonly class HistoricProcessingResultImportPlan
{
    /**
     * @param  'already_present'|'create'|'blocked_difference'|'conflict'  $classification
     * @param  array<string, mixed>  $service
     * @param  list<array{path: string, size: int, sha256: string, kind: string, roles: list<string>}>  $assets
     */
    public function __construct(
        public string $classification,
        public string $reason,
        public string $planHash,
        public string $bundleHash,
        public array $service,
        public array $assets,
        public ?int $existingProcessingLogId = null,
    ) {}
}
